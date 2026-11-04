<?php

namespace App\Http\Controllers;

use App\Actions\Billing\GrantAiCredits;
use App\Actions\Billing\TrackPlanChange;
use App\Models\Organization;
use Closure;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier's webhook handling (it keeps subscriptions in sync), with plan changes
 * recorded in the audit log, plus paid credit pack checkouts. The organization
 * always comes from the event's Stripe customer (organizations.stripe_id), never
 * from metadata. The signature is checked by the route's VerifyStripeWebhook
 * middleware, so Cashier's optional check is skipped here.
 */
class StripeWebhookController extends WebhookController
{
    // Cashier's constructor adds its own signature middleware, only when a secret is set.
    public function __construct() {}

    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        return $this->trackingPlan($payload, fn () => parent::handleCustomerSubscriptionCreated($payload));
    }

    /**
     * A canceled Stripe subscription can't come back, so a late or retried "updated"
     * event for one we already marked canceled is ignored (it would revive the plan).
     * Checked under the organization lock (TrackPlanChange): a "deleted" event handled
     * at the same time has committed by then.
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        return $this->trackingPlan($payload, function (Organization $organization) use ($payload) {
            $canceled = $organization->subscriptions()
                ->where('stripe_id', $payload['data']['object']['id'] ?? null)
                ->where('stripe_status', 'canceled')
                ->exists();

            return $canceled ? $this->successMethod() : parent::handleCustomerSubscriptionUpdated($payload);
        });
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        return $this->trackingPlan($payload, fn () => parent::handleCustomerSubscriptionDeleted($payload));
    }

    protected function handleCustomerDeleted(array $payload): Response
    {
        $organization = $this->organizationOf($payload['data']['object']['id'] ?? null);

        if ($organization === null) {
            return $this->successMethod();
        }

        return app(TrackPlanChange::class)->handle($organization, fn () => parent::handleCustomerDeleted($payload)) ?? $this->successMethod();
    }

    /**
     * A finished Checkout. Credit packs paid by card are "paid" here; slower payment
     * methods are paid later, in async_payment_succeeded. Granting is idempotent.
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        return $this->grantingCredits($payload);
    }

    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload): Response
    {
        return $this->grantingCredits($payload);
    }

    /**
     * @param  Closure(Organization): Response  $handle  run under the organization's lock
     */
    private function trackingPlan(array $payload, Closure $handle): Response
    {
        $organization = $this->organizationOf($payload['data']['object']['customer'] ?? null);

        // Not one of ours (or malformed): nothing to update, and no retries.
        if ($organization === null) {
            return $this->successMethod();
        }

        // Null: the organization was deleted meanwhile; nothing left to update.
        return app(TrackPlanChange::class)->handle($organization, $handle) ?? $this->successMethod();
    }

    private function grantingCredits(array $payload): Response
    {
        $session = $payload['data']['object'] ?? [];

        if (is_array($session) && ($organization = $this->organizationOf($session['customer'] ?? null))) {
            app(GrantAiCredits::class)->handle($organization, $session);
        }

        return $this->successMethod();
    }

    private function organizationOf(mixed $stripeId): ?Organization
    {
        $organization = is_string($stripeId) ? $this->getUserByStripeId($stripeId) : null;

        return $organization instanceof Organization ? $organization : null;
    }
}

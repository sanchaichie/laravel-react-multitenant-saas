<?php

namespace App\Actions\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Billing\Plans;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StartSubscriptionCheckout
{
    public function __construct(
        private readonly Plans $plans,
        private readonly BillingGateway $gateway,
    ) {}

    /**
     * The hosted checkout URL for subscribing the organization to a paid plan.
     * Nothing changes here; the webhook records the subscription once it's paid.
     *
     * @throws ValidationException
     */
    public function handle(Organization $organization, User $user, string $planKey): string
    {
        $plan = $this->plans->find($planKey);

        if ($plan === null || ! $plan->isPaid) {
            throw ValidationException::withMessages(['plan' => 'Choose a paid plan.']);
        }

        // Changing or canceling an existing subscription happens in the billing portal.
        // Two checkouts opened before either is paid can still both go through; the
        // webhook logs that (TrackPlanChange) so the extra one can be refunded.
        if ($this->plans->for($organization)->isPaid || $this->plans->paidSubscriptions($organization)->isNotEmpty()) {
            throw ValidationException::withMessages(['plan' => 'This organization already has a subscription. Use Manage billing to change it.']);
        }

        if ($plan->priceId === null) {
            throw ValidationException::withMessages(['plan' => 'This plan isn\'t available yet.']);
        }

        $billingUrl = config('app.frontend_url').'/billing';

        return $this->gateway->subscriptionCheckoutUrl(
            $organization,
            $user,
            $plan,
            successUrl: $billingUrl.'?checkout=success',
            cancelUrl: $billingUrl.'?checkout=canceled',
        );
    }
}

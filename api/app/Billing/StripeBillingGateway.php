<?php

namespace App\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Billing\Exceptions\BillingUnavailable;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Invoice as StripeInvoice;

/**
 * Stripe through Laravel Cashier: Checkout for payments, the Customer Portal for
 * everything after. Subscriptions are written by the webhook, not here.
 */
class StripeBillingGateway implements BillingGateway
{
    public function subscriptionCheckoutUrl(Organization $organization, User $user, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        return $this->call(function () use ($organization, $user, $plan, $successUrl, $cancelUrl) {
            $this->ensureCustomer($organization, $user);

            return $organization->newSubscription(Plans::SUBSCRIPTION, $plan->priceId)->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $organization->id,
            ])->url;
        });
    }

    public function creditsCheckoutUrl(Organization $organization, User $user, CreditPack $pack, string $successUrl, string $cancelUrl): string
    {
        return $this->call(function () use ($organization, $user, $pack, $successUrl, $cancelUrl) {
            $this->ensureCustomer($organization, $user);

            return $organization->checkout([$pack->priceId => 1], [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $organization->id,
                'metadata' => ['pack' => $pack->key, 'user_id' => (string) $user->id],
                // An invoice-style receipt for the purchase.
                'invoice_creation' => ['enabled' => true],
            ])->url;
        });
    }

    public function portalUrl(Organization $organization, string $returnUrl): string
    {
        return $this->call(fn () => $organization->billingPortalUrl($returnUrl));
    }

    public function invoices(Organization $organization, int $limit, ?string $startingAfter): InvoicePage
    {
        return $this->call(function () use ($organization, $limit, $startingAfter) {
            try {
                $list = Organization::stripe()->invoices->all(array_filter([
                    'customer' => $organization->stripeId(),
                    'limit' => $limit,
                    'starting_after' => $startingAfter,
                ]));
            } catch (InvalidRequestException $exception) {
                // A cursor that names no invoice: nothing after it (not "Stripe is down").
                if ($startingAfter !== null && $exception->getStripeParam() === 'starting_after') {
                    return InvoicePage::empty();
                }

                throw $exception;
            }

            $invoices = [];
            foreach ($list->data as $invoice) {
                // Drafts aren't final yet (and have no hosted page).
                if ($invoice->status !== StripeInvoice::STATUS_DRAFT) {
                    $invoices[] = $this->toInvoice($invoice);
                }
            }

            $last = $list->data === [] ? null : end($list->data);

            return new InvoicePage($invoices, $list->has_more && $last !== null ? $last->id : null);
        });
    }

    private function toInvoice(StripeInvoice $invoice): Invoice
    {
        return new Invoice(
            id: $invoice->id,
            number: $invoice->number,
            status: (string) $invoice->status,
            total: (int) $invoice->total,
            currency: (string) $invoice->currency,
            createdAt: CarbonImmutable::createFromTimestampUTC($invoice->created),
            description: $invoice->lines->data[0]->description ?? null,
            hostedUrl: self::stripeUrl($invoice->hosted_invoice_url ?? null),
            pdfUrl: self::stripeUrl($invoice->invoice_pdf ?? null),
        );
    }

    /**
     * Only Stripe's own pages are linked to from the app.
     */
    private static function stripeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        $isStripe = strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && ($host === 'stripe.com' || str_ends_with($host, '.stripe.com'));

        return $isStripe ? $url : null;
    }

    /**
     * One Stripe customer per organization, even when two people start a checkout at
     * the same time: the organization row is locked while the customer is created.
     */
    private function ensureCustomer(Organization $organization, User $user): void
    {
        if ($organization->hasStripeId()) {
            return;
        }

        DB::transaction(function () use ($organization, $user) {
            // FOR NO KEY UPDATE like every other organization lock: two checkouts wait for
            // each other here, inserts of tasks and usage don't wait on Stripe's answer.
            $locked = Organization::query()->whereKey($organization->id)->lock('for no key update')->firstOrFail();

            if (! $locked->hasStripeId()) {
                $locked->createAsStripeCustomer([
                    'email' => $user->email,
                    'metadata' => ['organization_id' => (string) $organization->id],
                ]);
            }

            $organization->forceFill(['stripe_id' => $locked->stripe_id])->syncOriginalAttribute('stripe_id');
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function call(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiErrorException $exception) {
            // Network, rate limit or Stripe's own errors pass; anything else (a wrong key or
            // price id, a customer Stripe doesn't know) needs someone to fix the setup.
            $transient = $exception instanceof ApiConnectionException
                || $exception instanceof RateLimitException
                || $exception->getHttpStatus() >= 500;
            Log::log($transient ? 'warning' : 'error', 'Stripe call failed.', [
                'error' => $exception->getMessage(),
                'stripe_code' => $exception->getStripeCode(),
                'http_status' => $exception->getHttpStatus(),
            ]);

            throw new BillingUnavailable(previous: $exception);
        }
    }
}

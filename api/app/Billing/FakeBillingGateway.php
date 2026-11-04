<?php

namespace App\Billing;

use App\Actions\Billing\GrantAiCredits;
use App\Actions\Billing\TrackPlanChange;
use App\Billing\Contracts\BillingGateway;
use App\Models\AiCreditPurchase;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * No network. Checkouts go straight to the success URL and the portal to its
 * return URL, and record what was asked for. Used by tests and by
 * BILLING_PROVIDER=fake (e2e runs; only with APP_ENV local or testing).
 *
 * In tests a checkout changes nothing (only the webhook does that). With
 * billing.fake_complete_checkout (e2e only) it also does what the webhook would
 * after a payment, through the same actions, so flows can show Pro and credits.
 */
class FakeBillingGateway implements BillingGateway
{
    /** @var list<array{organization_id: int, user_id: int, plan: string}> */
    private array $checkouts = [];

    /** @var list<int> */
    private array $portals = [];

    /** @var list<array{organization_id: int, user_id: int, pack: string}> */
    private array $creditCheckouts = [];

    /** @var array<int, list<Invoice>> by organization id */
    private array $invoices = [];

    /** @var list<array{organization_id: int, limit: int, starting_after: ?string}> */
    private array $invoiceRequests = [];

    public function subscriptionCheckoutUrl(Organization $organization, User $user, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        $this->checkouts[] = ['organization_id' => $organization->id, 'user_id' => $user->id, 'plan' => $plan->key];

        if (config('billing.fake_complete_checkout')) {
            $this->ensureCustomer($organization);
            app(TrackPlanChange::class)->handle($organization, fn () => $organization->subscriptions()->create([
                'type' => Plans::SUBSCRIPTION,
                'stripe_id' => 'sub_fake_'.Str::random(14),
                'stripe_status' => 'active',
                'stripe_price' => $plan->priceId,
                'quantity' => 1,
            ]));
        }

        return $successUrl;
    }

    public function creditsCheckoutUrl(Organization $organization, User $user, CreditPack $pack, string $successUrl, string $cancelUrl): string
    {
        $this->creditCheckouts[] = ['organization_id' => $organization->id, 'user_id' => $user->id, 'pack' => $pack->key];

        if (config('billing.fake_complete_checkout')) {
            $this->ensureCustomer($organization);
            app(GrantAiCredits::class)->handle($organization, [
                'id' => 'cs_fake_'.Str::random(14),
                'mode' => 'payment',
                'payment_status' => 'paid',
                'client_reference_id' => (string) $organization->id,
                'metadata' => ['pack' => $pack->key, 'user_id' => (string) $user->id],
            ]);
        }

        return $successUrl;
    }

    public function portalUrl(Organization $organization, string $returnUrl): string
    {
        if (! $organization->hasStripeId()) {
            throw new LogicException('The organization is not a billing customer.');
        }

        $this->portals[] = $organization->id;

        return $returnUrl;
    }

    public function invoices(Organization $organization, int $limit, ?string $startingAfter): InvoicePage
    {
        if (! $organization->hasStripeId()) {
            throw new LogicException('The organization is not a billing customer.');
        }

        $this->invoiceRequests[] = ['organization_id' => $organization->id, 'limit' => $limit, 'starting_after' => $startingAfter];

        $all = $this->invoices[$organization->id] ?? $this->demoInvoices($organization);

        // Like Stripe: an id that isn't one of this customer's invoices starts nowhere.
        $offset = 0;
        if ($startingAfter !== null) {
            $index = array_search($startingAfter, array_map(fn (Invoice $invoice) => $invoice->id, $all), true);
            if ($index === false) {
                return InvoicePage::empty();
            }
            $offset = $index + 1;
        }

        $page = array_slice($all, $offset, $limit);
        $hasMore = count($all) > $offset + $limit;

        return new InvoicePage($page, $hasMore ? end($page)->id : null);
    }

    /**
     * What invoices() returns for the organization (newest first), in place of the demo ones.
     *
     * @param  list<Invoice>  $invoices
     */
    public function setInvoices(Organization $organization, array $invoices): void
    {
        $this->invoices[$organization->id] = $invoices;
    }

    /**
     * @return list<array{organization_id: int, limit: int, starting_after: ?string}>
     */
    public function invoiceRequests(): array
    {
        return $this->invoiceRequests;
    }

    /**
     * @return list<array{organization_id: int, user_id: int, plan: string}>
     */
    public function checkouts(): array
    {
        return $this->checkouts;
    }

    /**
     * @return list<array{organization_id: int, user_id: int, pack: string}>
     */
    public function creditCheckouts(): array
    {
        return $this->creditCheckouts;
    }

    /**
     * @return list<int> organization ids
     */
    public function portals(): array
    {
        return $this->portals;
    }

    public function assertNothingStarted(): void
    {
        Assert::assertSame([], $this->checkouts, 'A checkout was started.');
        Assert::assertSame([], $this->creditCheckouts, 'A credits checkout was started.');
        Assert::assertSame([], $this->portals, 'A billing portal session was started.');
        Assert::assertSame([], $this->invoiceRequests, 'Invoices were requested.');
    }

    private function ensureCustomer(Organization $organization): void
    {
        if (! $organization->hasStripeId()) {
            $organization->forceFill(['stripe_id' => 'cus_fake_'.$organization->id])->save();
        }
    }

    /**
     * For e2e runs: one paid invoice per subscription and per credit purchase,
     * linking back to the invoices page.
     *
     * @return list<Invoice>
     */
    private function demoInvoices(Organization $organization): array
    {
        $url = config('app.frontend_url').'/billing/invoices';
        $invoices = [];

        foreach ($organization->subscriptions()->get() as $subscription) {
            $plan = collect(config('billing.plans'))->first(fn (array $plan) => $plan['price'] !== null && $plan['price'] === $subscription->stripe_price);
            $invoices[] = new Invoice(
                id: 'in_fake_sub_'.$subscription->id,
                number: 'FAKE-S'.$subscription->id,
                status: 'paid',
                total: (int) round((float) preg_replace('/[^0-9.]/', '', $plan['display_price'] ?? '0') * 100),
                currency: 'usd',
                createdAt: CarbonImmutable::parse($subscription->created_at),
                description: $plan['name'] ?? 'Subscription',
                hostedUrl: $url,
                pdfUrl: null,
            );
        }

        // Explicit organization (not the scope), as AiBudget does.
        $purchases = AiCreditPurchase::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->get();
        foreach ($purchases as $purchase) {
            $invoices[] = new Invoice(
                id: 'in_fake_credits_'.$purchase->id,
                number: 'FAKE-C'.$purchase->id,
                status: 'paid',
                total: (int) round((float) $purchase->amount_usd * 100),
                currency: 'usd',
                createdAt: CarbonImmutable::parse($purchase->created_at),
                description: config("billing.credit_packs.{$purchase->pack}.name", 'AI credits'),
                hostedUrl: $url,
                pdfUrl: null,
            );
        }

        usort($invoices, fn (Invoice $a, Invoice $b) => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $invoices;
    }
}

<?php

namespace App\Billing;

use App\Models\Organization;
use Illuminate\Support\Collection;
use Laravel\Cashier\Subscription;

/**
 * The plans in config/billing.php, and which one an organization is on.
 */
class Plans
{
    // Cashier's subscription type for the plan subscription.
    public const SUBSCRIPTION = 'default';

    public const FREE = 'free';

    // Stripe statuses of a subscription that is still being paid (past_due: Stripe is
    // retrying a failed renewal; see Cashier::keepPastDueSubscriptionsActive).
    private const PAID_STATUSES = ['active', 'trialing', 'past_due'];

    /**
     * @return array<string, Plan>
     */
    public function all(): array
    {
        $plans = [];

        foreach (config('billing.plans') as $key => $plan) {
            $isPaid = $key !== self::FREE;
            $plans[$key] = new Plan(
                key: $key,
                name: $plan['name'],
                displayPrice: $plan['display_price'],
                priceId: $isPaid ? ($plan['price'] ?: null) : null,
                aiMonthlyBudgetUsd: $isPaid ? (float) $plan['ai_monthly_budget_usd'] : (float) config('ai.monthly_budget_usd'),
                isPaid: $isPaid,
            );
        }

        return $plans;
    }

    public function find(string $key): ?Plan
    {
        return $this->all()[$key] ?? null;
    }

    public function free(): Plan
    {
        return $this->all()[self::FREE];
    }

    /**
     * @return list<string>
     */
    public function paidKeys(): array
    {
        return array_keys(array_filter($this->all(), fn (Plan $plan) => $plan->isPaid));
    }

    /**
     * The plan of the organization's valid subscription (active, trialing, past due,
     * or canceled but not ended yet), else free. A subscription to a price that's no
     * longer in the config (e.g. after a price change) still counts as the first paid
     * plan, so a paying organization never drops to free because of a config edit;
     * keep plan switching off in the Customer Portal so that stays the only case.
     */
    public function for(Organization $organization): Plan
    {
        $subscription = $this->subscriptions($organization)->first(fn (Subscription $subscription) => $subscription->valid());

        return $subscription === null ? $this->free() : $this->planOf($subscription);
    }

    /**
     * The plan as Stripe last reported it, ignoring end dates: a subscription canceled
     * at the period end counts until Stripe deletes it. Used to audit plan changes when
     * Stripe confirms them, not when a date passes.
     */
    public function reportedFor(Organization $organization): Plan
    {
        $subscription = $this->paidSubscriptions($organization)->first();

        return $subscription === null ? $this->free() : $this->planOf($subscription);
    }

    /**
     * Plan subscriptions Stripe still bills. More than one means the organization paid
     * twice (e.g. two checkouts in two tabs).
     *
     * @return Collection<int, Subscription>
     */
    public function paidSubscriptions(Organization $organization): Collection
    {
        return $this->subscriptions($organization)
            ->filter(fn (Subscription $subscription) => in_array($subscription->stripe_status, self::PAID_STATUSES, true))
            ->values();
    }

    /**
     * Paid subscriptions Stripe will charge again: not canceled, or canceled but not
     * yet at the end of the period (on grace, it won't renew).
     *
     * @return Collection<int, Subscription>
     */
    public function renewingSubscriptions(Organization $organization): Collection
    {
        return $this->paidSubscriptions($organization)
            ->reject(fn (Subscription $subscription) => $subscription->onGracePeriod())
            ->values();
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function subscriptions(Organization $organization): Collection
    {
        return $organization->subscriptions->where('type', self::SUBSCRIPTION)->values();
    }

    private function planOf(Subscription $subscription): Plan
    {
        $paid = array_filter($this->all(), fn (Plan $plan) => $plan->isPaid);

        foreach ($paid as $plan) {
            if ($plan->priceId !== null && $plan->priceId === $subscription->stripe_price) {
                return $plan;
            }
        }

        return reset($paid) ?: $this->free();
    }
}

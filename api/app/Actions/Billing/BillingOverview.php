<?php

namespace App\Actions\Billing;

use App\Ai\AiBudget;
use App\Billing\CreditPack;
use App\Billing\CreditPacks;
use App\Billing\Plan;
use App\Billing\Plans;
use App\Models\Organization;

/**
 * The organization's plan, subscription and AI budget, for the billing page.
 * Read from our database only (kept in sync by the webhook): no Stripe call.
 */
class BillingOverview
{
    public function __construct(
        private readonly Plans $plans,
        private readonly CreditPacks $packs,
        private readonly AiBudget $budget,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Organization $organization): array
    {
        $plan = $this->plans->for($organization);
        $subscription = $organization->subscription(Plans::SUBSCRIPTION);
        $aiOff = $organization->ai_monthly_budget_usd !== null && (float) $organization->ai_monthly_budget_usd === 0.0;

        return [
            'plan' => $plan->toArray(),
            'subscription' => $subscription === null || ($subscription->stripe_status === 'canceled' && ! $subscription->onGracePeriod()) ? null : [
                // active, trialing, past_due (Stripe is retrying the card), incomplete...
                'status' => $subscription->stripe_status,
                // Set once it's canceled: the plan stays until then (grace period).
                'ends_at' => $subscription->ends_at?->toIso8601ZuluString(),
                'on_grace_period' => $subscription->onGracePeriod(),
            ],
            'payment_method' => $organization->pm_type === null ? null : [
                'brand' => $organization->pm_type,
                'last_four' => $organization->pm_last_four,
            ],
            'has_billing_account' => $organization->hasStripeId(),
            'plans' => array_values(array_map(fn (Plan $plan) => $plan->toArray(), $this->plans->all())),
            'credit_packs' => array_values(array_map(fn (CreditPack $pack) => $pack->toArray(), $this->packs->all())),
            'ai_budget' => $this->budget->summary($organization),
            'can' => [
                'subscribe' => ! $plan->isPaid && $this->plans->paidSubscriptions($organization)->isEmpty(),
                'open_portal' => $organization->hasStripeId(),
                'buy_credits' => ! $aiOff,
            ],
        ];
    }
}

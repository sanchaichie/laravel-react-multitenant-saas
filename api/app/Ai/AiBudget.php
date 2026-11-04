<?php

namespace App\Ai;

use App\Ai\Exceptions\AiBudgetExceeded;
use App\Billing\Plans;
use App\Models\AiCreditPurchase;
use App\Models\AiUsage;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\CurrentOrganization;
use Carbon\CarbonImmutable;

/**
 * Monthly AI spend per organization, from ai_usage.cost_usd since the start of
 * the UTC month. Checked before each call; calls running at the same time can
 * go slightly over the limit.
 */
class AiBudget
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly Plans $plans,
    ) {}

    /**
     * USD this month: the plan's budget (or the organization's own budget when it's
     * lower), plus AI credits bought this month. The plan is the ceiling, so an
     * organization can cap its spend but not give itself more than it pays for.
     * An own budget of 0 turns AI off, credits or not.
     */
    public function limit(Organization $organization, ?float $credits = null): float
    {
        $own = $organization->ai_monthly_budget_usd;

        if ($own !== null && (float) $own === 0.0) {
            return 0.0;
        }

        $plan = $this->planLimit($organization);

        return ($own === null ? $plan : min((float) $own, $plan)) + ($credits ?? $this->credits($organization));
    }

    /**
     * The most the organization's plan allows per month, before credits. Also what an
     * organization without its own budget gets.
     */
    public function planLimit(Organization $organization): float
    {
        return $this->plans->for($organization)->aiMonthlyBudgetUsd;
    }

    /**
     * USD of AI credits bought in the (UTC) month. They don't carry over.
     */
    public function credits(Organization $organization, ?CarbonImmutable $month = null): float
    {
        $from = ($month ?? CarbonImmutable::now('UTC'))->startOfMonth();

        // Explicit organization (not the scope), so it also works for one that isn't current.
        return (float) AiCreditPurchase::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $from->addMonth())
            ->sum('amount_usd');
    }

    public function spent(Organization $organization, ?CarbonImmutable $month = null): float
    {
        $from = ($month ?? CarbonImmutable::now('UTC'))->startOfMonth();

        // Explicit organization (not the scope), so it also works for one that isn't current.
        return (float) AiUsage::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $from->addMonth())
            ->sum('cost_usd');
    }

    public function remaining(Organization $organization): float
    {
        return max(0.0, $this->limit($organization) - $this->spent($organization));
    }

    /**
     * The current month's budget as the API shows it. default_limit_usd is the plan's
     * budget: what applies without an own budget, and the most an own budget can be.
     *
     * @return array{limit_usd: float, is_default: bool, own_limit_usd: float|null, default_limit_usd: float, plan: string, credits_usd: float, credits_expire_at: string, spent_usd: float, remaining_usd: float}
     */
    public function summary(Organization $organization): array
    {
        $credits = $this->credits($organization);
        $limit = $this->limit($organization, $credits);
        $spent = $this->spent($organization);

        return [
            'limit_usd' => $limit,
            'is_default' => $organization->ai_monthly_budget_usd === null,
            'own_limit_usd' => $organization->ai_monthly_budget_usd === null ? null : (float) $organization->ai_monthly_budget_usd,
            'default_limit_usd' => $this->planLimit($organization),
            'plan' => $this->plans->for($organization)->name,
            'credits_usd' => $credits,
            // Credits last until the end of the UTC month they're bought in.
            'credits_expire_at' => CarbonImmutable::now('UTC')->startOfMonth()->addMonth()->toIso8601ZuluString(),
            'spent_usd' => round($spent, 6),
            'remaining_usd' => round(max(0.0, $limit - $spent), 6),
        ];
    }

    public function exceeded(?Organization $organization = null): bool
    {
        $organization ??= $this->currentOrganization->get();

        // No organization means no tenant to bill; the caller's own checks apply.
        return $organization !== null && $this->remaining($organization) <= 0;
    }

    /**
     * @throws AiBudgetExceeded
     */
    public function ensureAvailable(?Organization $organization = null): void
    {
        if ($this->exceeded($organization)) {
            throw new AiBudgetExceeded;
        }
    }
}

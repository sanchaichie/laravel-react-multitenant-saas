<?php

namespace App\Ai;

use App\Ai\Exceptions\AiBudgetExceeded;
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
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * USD this month: the organization's own budget, or the default. 0 turns AI off.
     */
    public function limit(Organization $organization): float
    {
        return $organization->ai_monthly_budget_usd === null
            ? $this->defaultLimit()
            : (float) $organization->ai_monthly_budget_usd;
    }

    public function defaultLimit(): float
    {
        return (float) config('ai.monthly_budget_usd');
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
     * The current month's budget as the API shows it.
     *
     * @return array{limit_usd: float, is_default: bool, own_limit_usd: float|null, default_limit_usd: float, spent_usd: float, remaining_usd: float}
     */
    public function summary(Organization $organization): array
    {
        $limit = $this->limit($organization);
        $spent = $this->spent($organization);

        return [
            'limit_usd' => $limit,
            'is_default' => $organization->ai_monthly_budget_usd === null,
            'own_limit_usd' => $organization->ai_monthly_budget_usd === null ? null : (float) $organization->ai_monthly_budget_usd,
            'default_limit_usd' => $this->defaultLimit(),
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

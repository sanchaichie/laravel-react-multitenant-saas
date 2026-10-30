<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * AI usage of the current organization (rows are limited to it by BelongsToOrganization)
 * and its monthly budget.
 */
class AiUsagePolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->can(Permission::AiUsageView);
    }

    public function manageBudget(User $user): bool
    {
        return $this->currentOrganization->can(Permission::AiBudgetManage);
    }
}

<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Billing of the current organization (its plan, payment details and invoices).
 * Always the current one: no route names an organization.
 */
class BillingPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function manage(User $user): bool
    {
        return $this->currentOrganization->can(Permission::BillingManage);
    }
}

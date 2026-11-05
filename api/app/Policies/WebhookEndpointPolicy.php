<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\CurrentOrganization;

/**
 * Webhooks of the current organization (the scope limits which can be found). All
 * of it needs webhooks.manage: an endpoint sends every task's content out of the
 * app. No default role has it, so Owner only unless a custom role gets it.
 */
class WebhookEndpointPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->can(Permission::WebhooksManage);
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->viewAny($user);
    }
}

<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Invitations of the current organization (the model's scope already limits
 * which ones can be found).
 */
class OrganizationInvitationPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->can(Permission::MembersInvite);
    }

    /**
     * Invite with a role: only roles you include (an admin can't invite an owner).
     */
    public function create(User $user, OrganizationRole $role): bool
    {
        $actor = $this->currentOrganization->role();

        return $actor !== null && $actor->can(Permission::MembersInvite) && $actor->includes($role);
    }

    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        $actor = $this->currentOrganization->role();

        return $actor !== null && $actor->can(Permission::MembersInvite) && $actor->includes($invitation->role);
    }
}

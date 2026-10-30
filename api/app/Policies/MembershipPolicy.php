<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Who may manage members of the current organization. The target membership is
 * always looked up inside the current organization before these checks run.
 * Permissions, never role names; the "keep an owner" rule lives in the actions.
 */
class MembershipPolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->role() !== null;
    }

    /**
     * Change a member's role: only roles you include, on both sides (an admin
     * can't touch an owner, or make one).
     */
    public function update(User $user, Membership $membership, Role $newRole): bool
    {
        $actor = $this->currentOrganization->role();

        return $actor !== null
            && $actor->can(Permission::MembersManage)
            && $actor->includes($membership->role)
            && $actor->includes($newRole);
    }

    public function delete(User $user, Membership $membership): bool
    {
        // Anyone may leave.
        if ($membership->user_id === $user->id) {
            return true;
        }

        $actor = $this->currentOrganization->role();

        return $actor !== null
            && $actor->can(Permission::MembersManage)
            && $actor->includes($membership->role);
    }
}

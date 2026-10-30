<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Roles of the current organization (the scope limits which can be found). You
 * only grant permissions you have, and only edit or delete roles that can do
 * strictly less than yours: not your own, not a peer's (emptying a peer's role
 * would be a removal you couldn't make directly), never the owner role.
 */
class RolePolicy
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    // Anyone in the organization may see what each role allows.
    public function viewAny(User $user): bool
    {
        return $this->currentOrganization->role() !== null;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function create(User $user, array $permissions = []): bool
    {
        $actor = $this->currentOrganization->role();

        return $actor !== null && $actor->can(Permission::RolesManage) && $actor->covers($permissions);
    }

    /**
     * @param  list<Permission>|null  $permissions  the new ones; null to ask whether the role may be edited at all
     */
    public function update(User $user, Role $role, ?array $permissions = null): bool
    {
        return $this->mayChange($role) && $this->currentOrganization->role()->covers($permissions ?? []);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->mayChange($role);
    }

    private function mayChange(Role $role): bool
    {
        $actor = $this->currentOrganization->role();

        return $actor !== null
            && $actor->can(Permission::RolesManage)
            && ! $role->is_owner
            && $actor->includesStrictly($role);
    }
}

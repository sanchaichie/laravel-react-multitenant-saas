<?php

namespace App\Actions\Roles;

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\Role;
use App\Support\CurrentOrganization;

class CreateDefaultRoles
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * Give a new organization its Owner, Admin and Member roles. Not recorded in the
     * audit log: creating the organization is (organization.created).
     *
     * @return array<string, Role> keyed by DefaultRole value
     */
    public function handle(Organization $organization): array
    {
        return $this->currentOrganization->run($organization, fn () => Role::withoutAuditing(function () {
            $roles = [];

            foreach (DefaultRole::cases() as $default) {
                $role = new Role(['name' => $default->label(), 'permissions' => $default->permissions()]);
                $role->is_owner = $default->isOwner();
                $role->save();
                $roles[$default->value] = $role;
            }

            return $roles;
        }));
    }
}

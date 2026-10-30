<?php

namespace App\Actions\Roles;

use App\Actions\Roles\Concerns\SavesRole;
use App\Enums\Permission;
use App\Models\Role;
use LogicException;

class UpdateRole
{
    use SavesRole;

    /**
     * @param  list<Permission>  $permissions
     */
    public function handle(Role $role, string $name, array $permissions): Role
    {
        // The policy refuses it first; this keeps the owner role intact whoever calls.
        if ($role->is_owner) {
            throw new LogicException('The owner role can\'t be changed.');
        }

        return $this->save($role, $name, $permissions);
    }
}

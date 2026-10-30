<?php

namespace App\Actions\Roles;

use App\Actions\Roles\Concerns\SavesRole;
use App\Enums\Permission;
use App\Models\Role;

class CreateRole
{
    use SavesRole;

    /**
     * A role in the current organization (BelongsToOrganization sets it).
     *
     * @param  list<Permission>  $permissions
     */
    public function handle(string $name, array $permissions): Role
    {
        return $this->save(new Role, $name, $permissions);
    }
}

<?php

namespace App\Actions\Roles\Concerns;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait SavesRole
{
    /**
     * @param  list<Permission>  $permissions
     */
    private function save(Role $role, string $name, array $permissions): Role
    {
        // Stored in catalogue order, without duplicates, so equal sets compare equal.
        $role->fill([
            'name' => $name,
            'permissions' => array_values(array_filter(Permission::cases(), fn (Permission $p) => in_array($p, $permissions, true))),
        ]);

        try {
            DB::transaction(function () use ($role) {
                // An edit of a role deleted meanwhile would update nothing and still
                // answer 200: lock it first, and 404 if it's gone.
                if ($role->exists) {
                    Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
                }

                $role->save();
            });
        } catch (UniqueConstraintViolationException) {
            // The request checked the name; another role took it at the same moment.
            throw ValidationException::withMessages(['name' => 'Another role already has this name.']);
        }

        return $role->loadCount('memberships');
    }
}

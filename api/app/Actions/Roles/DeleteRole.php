<?php

namespace App\Actions\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class DeleteRole
{
    /**
     * Delete a role nobody has. Its pending invitations lose their role (the foreign
     * key sets it to null) and can no longer be accepted.
     */
    public function handle(Role $role): void
    {
        if ($role->is_owner) {
            throw new LogicException('The owner role can\'t be deleted.');
        }

        DB::transaction(function () use ($role) {
            // FOR UPDATE: assigning a role locks it FOR SHARE first (LocksRole), so a
            // member being given this role right now is either counted here or waits,
            // then finds it gone.
            $locked = Role::query()->whereKey($role->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $members = $locked->memberships()->count();
            if ($members > 0) {
                throw ValidationException::withMessages([
                    'role' => trans_choice(
                        '{1} One member has this role. Give them another role first.|[2,*] :count members have this role. Give them another role first.',
                        $members,
                    ),
                ]);
            }

            $locked->delete();
        });
    }
}

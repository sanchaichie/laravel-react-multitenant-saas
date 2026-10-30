<?php

namespace App\Actions\Organizations\Concerns;

use App\Models\Role;
use Illuminate\Validation\ValidationException;

trait LocksRole
{
    /**
     * Lock the role being given FOR SHARE, so DeleteRole (FOR UPDATE, then counts its
     * members) waits for this transaction and sees the new member, or runs first and
     * this fails with a message instead of a foreign key error. Take it right after
     * the organization row, before membership or invitation rows: DeleteRole holds
     * the role while it nulls out invitations, so the reverse order could deadlock.
     * (AcceptInvitation does the same inline: it starts from a token, not a Role.)
     */
    protected function lockRole(Role $role, string $errorKey, ?Role $actor): Role
    {
        $locked = Role::query()->whereKey($role->id)->sharedLock()->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                $errorKey => 'That role was just deleted. Reload the page and choose another one.',
            ]);
        }

        // The policy checked the role as it was before the lock. If someone widened it
        // since, you may no longer be allowed to give it; the lock keeps this answer
        // true until commit.
        if ($actor === null || ! $actor->includes($locked)) {
            throw ValidationException::withMessages([
                $errorKey => 'That role just changed and now allows more than yours. Reload the page.',
            ]);
        }

        return $locked;
    }
}

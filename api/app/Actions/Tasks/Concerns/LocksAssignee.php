<?php

namespace App\Actions\Tasks\Concerns;

use App\Models\Membership;
use Illuminate\Validation\ValidationException;

trait LocksAssignee
{
    /**
     * Check, inside the transaction, that the assignee is still a member, and hold a
     * FOR SHARE lock on their membership until it commits. The form request checked
     * already, but someone removed in between would otherwise end up assigned: with
     * the lock, RemoveMember's delete waits for this task to be saved, then unassigns it.
     */
    protected function lockAssignee(int $organizationId, ?int $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }

        $isMember = Membership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $assigneeId)
            ->sharedLock()
            ->first() !== null;

        if (! $isMember) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The assignee must be a member of this organization.',
            ]);
        }
    }
}

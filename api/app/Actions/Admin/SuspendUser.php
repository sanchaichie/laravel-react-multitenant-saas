<?php

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ActsOnUsers;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuspendUser
{
    use ActsOnUsers;

    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * Suspend the user and sign out every device. Login, the two-factor challenge,
     * password reset and any token refuse them until UnsuspendUser. No email: they
     * find out when they log in.
     *
     * @throws ValidationException a platform admin, or already suspended
     */
    public function handle(User $user, string $reason): User
    {
        return DB::transaction(function () use ($user, $reason) {
            $locked = $this->lockedTarget($user);

            if ($locked->isSuspended()) {
                throw ValidationException::withMessages(['user' => 'This user is already suspended.']);
            }

            $locked->forceFill(['suspended_at' => now()])->save();
            $locked->tokens()->delete();

            $this->recordAudit->handle(PlatformAuditAction::UserSuspended, RecordPlatformAudit::user($locked), ['reason' => $reason]);

            return $locked;
        });
    }
}

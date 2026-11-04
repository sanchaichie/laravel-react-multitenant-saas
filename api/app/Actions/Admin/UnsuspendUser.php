<?php

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ActsOnUsers;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnsuspendUser
{
    use ActsOnUsers;

    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * Lift a suspension. They log in again as normal (their old tokens are gone).
     *
     * @throws ValidationException a platform admin, or not suspended
     */
    public function handle(User $user, string $reason): User
    {
        return DB::transaction(function () use ($user, $reason) {
            $locked = $this->lockedTarget($user);

            if (! $locked->isSuspended()) {
                throw ValidationException::withMessages(['user' => "This user isn't suspended."]);
            }

            $locked->forceFill(['suspended_at' => null])->save();

            $this->recordAudit->handle(PlatformAuditAction::UserUnsuspended, RecordPlatformAudit::user($locked), ['reason' => $reason]);

            return $locked;
        });
    }
}

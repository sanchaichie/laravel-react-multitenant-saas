<?php

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\ActsOnUsers;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SignOutUser
{
    use ActsOnUsers;

    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * Sign the user out of every device (a reported stolen laptop, say).
     *
     * @throws ValidationException a platform admin
     */
    public function handle(User $user, string $reason): User
    {
        return DB::transaction(function () use ($user, $reason) {
            $locked = $this->lockedTarget($user);

            $devices = $locked->tokens()->delete();

            $this->recordAudit->handle(PlatformAuditAction::UserSignedOut, RecordPlatformAudit::user($locked), [
                'reason' => $reason,
                'devices' => $devices,
            ]);

            return $locked;
        });
    }
}

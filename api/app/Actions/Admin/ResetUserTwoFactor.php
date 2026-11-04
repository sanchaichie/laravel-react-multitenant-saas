<?php

namespace App\Actions\Admin;

use App\Actions\Account\DisableTwoFactor;
use App\Actions\Admin\Concerns\ActsOnUsers;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use App\Notifications\TwoFactorResetByAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResetUserTwoFactor
{
    use ActsOnUsers;

    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * For a lost phone: turn the user's two-factor off and sign out every device, so
     * they log in with their password and set it up again. They get an email. A
     * pending login challenge dies too (its fingerprint includes two_factor_confirmed_at).
     *
     * @throws ValidationException a platform admin, or two-factor is off
     */
    public function handle(User $user, string $reason): User
    {
        $user = DB::transaction(function () use ($user, $reason) {
            $locked = $this->lockedTarget($user);

            if (! $locked->hasTwoFactorEnabled()) {
                throw ValidationException::withMessages(['user' => 'Two-factor is already off for this user.']);
            }

            DisableTwoFactor::clear($locked);
            $locked->tokens()->delete();

            $this->recordAudit->handle(PlatformAuditAction::TwoFactorReset, RecordPlatformAudit::user($locked), ['reason' => $reason]);

            return $locked;
        });

        $user->notify(new TwoFactorResetByAdmin);

        return $user;
    }
}

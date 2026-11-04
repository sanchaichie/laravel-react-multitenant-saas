<?php

namespace App\Actions\Account;

use App\Actions\Admin\RecordPlatformAudit;
use App\Actions\Auth\CheckTwoFactorCode;
use App\Enums\PlatformAuditAction;
use App\Models\User;
use App\Notifications\TwoFactorDisabled;
use Illuminate\Validation\ValidationException;

class DisableTwoFactor
{
    public function __construct(private readonly CheckTwoFactorCode $checkCode) {}

    /**
     * Turn two-factor off (the request checked the password) with a current code, or
     * a recovery code when the phone is gone: a stolen session plus a phished
     * password isn't enough to remove it. The account gets an email.
     *
     * @throws ValidationException off already, or a wrong code
     */
    public function handle(User $user, ?string $code, ?string $recoveryCode): void
    {
        $this->checkCode->run($user, $code, $recoveryCode, function (User $locked) {
            self::clear($locked);
        });

        $user->notify(new TwoFactorDisabled);
    }

    /**
     * The columns that hold two-factor, cleared (also used by RevertTwoFactor and
     * RevertEmailChange), on a row the caller has locked. A platform admin stops being
     * one: otherwise a password alone could swap in a new authenticator. Only
     * `admin:grant` makes them one again.
     */
    public static function clear(User $user): void
    {
        if ($user->isPlatformAdmin()) {
            $user->forceFill(['is_platform_admin' => false]);
            app(RecordPlatformAudit::class)->handle(
                PlatformAuditAction::AdminRevoked,
                RecordPlatformAudit::user($user),
                ['reason' => 'two_factor_disabled'],
            );
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
    }
}

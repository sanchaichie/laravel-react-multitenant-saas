<?php

namespace App\Actions\Account;

use App\Actions\Auth\CheckTwoFactorCode;
use App\Models\User;
use App\Notifications\RecoveryCodesRegenerated;
use App\Support\RecoveryCodes;
use Illuminate\Validation\ValidationException;

class RegenerateRecoveryCodes
{
    public function __construct(private readonly CheckTwoFactorCode $checkCode) {}

    /**
     * A new set of recovery codes (the request checked the password, this a current
     * code from the app); the old ones stop working. The account gets an email,
     * since a new set is a lasting way in.
     *
     * @return list<string> shown once
     *
     * @throws ValidationException two-factor off, or a wrong code
     */
    public function handle(User $user, string $code): array
    {
        $codes = $this->checkCode->run($user, $code, null, function (User $locked) {
            $codes = RecoveryCodes::generate();
            $locked->forceFill(['two_factor_recovery_codes' => RecoveryCodes::hashAll($codes)])->save();

            return $codes;
        });

        $user->notify(new RecoveryCodesRegenerated);

        return $codes;
    }
}

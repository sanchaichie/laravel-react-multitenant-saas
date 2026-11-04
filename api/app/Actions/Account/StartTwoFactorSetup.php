<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartTwoFactorSetup
{
    /**
     * A new secret for the authenticator app. Two-factor stays off until
     * ConfirmTwoFactor gets a code made from it; starting again replaces it.
     *
     * @return array{secret: string, otpauth_uri: string}
     *
     * @throws ValidationException already on
     */
    public function handle(User $user): array
    {
        $secret = Totp::generateSecret();

        DB::transaction(function () use ($user, $secret) {
            // Locked, so a confirm in another tab can't be overwritten.
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($user->hasTwoFactorEnabled()) {
                throw ValidationException::withMessages([
                    'two_factor' => ['Two-factor authentication is already on.'],
                ]);
            }

            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_step' => null,
            ])->save();
        });

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $user->email, config('app.name')),
        ];
    }
}

<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\TwoFactorEnabled;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ConfirmTwoFactor
{
    /**
     * Turn two-factor on with a code made from the new secret, and sign out every
     * other device (like a password change). The token of this request keeps working,
     * but doesn't count as having passed a code for admin routes (two_factor_at): the
     * factor is brand new, and only the password stood behind setting it up.
     *
     * @return list<string> the recovery codes, shown once
     *
     * @throws ValidationException no setup started, already on, or a wrong code
     */
    public function handle(User $user, string $code, ?PersonalAccessToken $currentToken): array
    {
        $codes = DB::transaction(function () use ($user, $code, $currentToken) {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            // Signed out meanwhile (a reset, another device): this session can't turn it on.
            if ($currentToken !== null && ! $user->tokens()->whereKey($currentToken->getKey())->exists()) {
                throw new AuthenticationException;
            }

            if ($user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
                throw ValidationException::withMessages([
                    'code' => ['Start the setup again.'],
                ]);
            }

            $step = Totp::verify($user->two_factor_secret, $code);
            if ($step === null) {
                throw ValidationException::withMessages([
                    'code' => ["That code isn't right. Check the time on your phone and try the newest code."],
                ]);
            }

            $codes = RecoveryCodes::generate();
            $user->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_step' => $step,
                'two_factor_recovery_codes' => RecoveryCodes::hashAll($codes),
            ])->save();

            $user->tokens()
                ->when($currentToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                ->delete();

            return $codes;
        });

        // After the commit, so a rollback never sends it.
        $user->refresh()->notify(new TwoFactorEnabled($user->id, $user->two_factor_confirmed_at->getTimestamp()));

        return $codes;
    }
}

<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class RevertTwoFactor
{
    /**
     * The "this wasn't me" link from the "two-factor was turned on" email (no login:
     * whoever follows it may be locked out). Someone with the password may have set
     * it up with their own phone, and then a password reset alone wouldn't get the
     * owner back in. The route checks the signature and expiry; this checks it's
     * for this exact setup. Two-factor goes, every device is signed out, and a reset
     * link goes to the account's email.
     *
     * @throws AuthorizationException a link for another setup, or one already used
     */
    public function handle(int $id, int $confirmedAt): void
    {
        $user = DB::transaction(function () use ($id, $confirmedAt) {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();

            if ($user === null || ! $user->hasTwoFactorEnabled()
                || $user->two_factor_confirmed_at->getTimestamp() !== $confirmedAt) {
                throw new AuthorizationException('This link was already used, or two-factor authentication was set up again since.');
            }

            DisableTwoFactor::clear($user);
            $user->tokens()->delete();

            return $user;
        });

        // After the commit, to the account's email.
        Password::broker()->sendResetLink(['email' => $user->email]);
    }
}

<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class RevertEmailChange
{
    /**
     * Undo the last email change from the link sent to the old address (no login: the
     * person following it may be locked out). The route checks the signature and
     * expiry; this checks it's for this exact change. The old address comes back
     * (verified: the link reached it), every device is signed out, and a password
     * reset link goes to it, since whoever changed the email had the password.
     * Two-factor set up since the change is turned off too: whoever had the account
     * may have set it up with their own phone, which would keep the owner out even
     * after the reset. One set up before the change stays.
     *
     * @return bool whether two-factor was turned off
     *
     * @throws AuthorizationException a link for another change, or one already used
     * @throws ValidationException the old address is now another account's
     */
    public function handle(int $id, string $oldHash, string $newHash): bool
    {
        [$user, $hadTwoFactor] = DB::transaction(function () use ($id, $oldHash, $newHash) {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();

            if ($user === null || $user->previous_email === null
                || ! hash_equals(sha1($user->previous_email), $oldHash)
                || ! hash_equals(sha1($user->email), $newHash)) {
                throw new AuthorizationException('This link was already used, or the email has changed again since.');
            }

            if (User::query()->where('email', $user->previous_email)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['email' => ['Another account uses that address now. Contact the people who run this app.']]);
            }

            $user->forceFill([
                'email' => $user->previous_email,
                'email_verified_at' => now(),
                'previous_email' => null,
                'pending_email' => null,
            ])->save();
            $user->tokens()->delete();

            // Only a setup from after the change: one the owner made before it stays,
            // so the old inbox alone still can't log in without their phone.
            // Without email_changed_at (a change from before it existed), keep it: the
            // owner's two-factor is worth more than undoing someone else's.
            $setUpSince = $user->two_factor_secret !== null && ($user->two_factor_confirmed_at === null
                || ($user->email_changed_at !== null && $user->two_factor_confirmed_at->gte($user->email_changed_at)));
            $hadTwoFactor = $setUpSince && $user->hasTwoFactorEnabled();
            if ($setUpSince) {
                DisableTwoFactor::clear($user);
            }
            $user->forceFill(['email_changed_at' => null])->save();

            return [$user, $hadTwoFactor];
        });

        // After the commit, to the address that's the account's again.
        Password::broker()->sendResetLink(['email' => $user->email]);

        return $hadTwoFactor;
    }
}

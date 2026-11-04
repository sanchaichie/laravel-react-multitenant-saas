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
     *
     * @throws AuthorizationException a link for another change, or one already used
     * @throws ValidationException the old address is now another account's
     */
    public function handle(int $id, string $oldHash, string $newHash): void
    {
        $user = DB::transaction(function () use ($id, $oldHash, $newHash) {
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

            return $user;
        });

        // After the commit, to the address that's the account's again.
        Password::broker()->sendResetLink(['email' => $user->email]);
    }
}

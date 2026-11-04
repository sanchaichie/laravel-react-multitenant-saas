<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\EmailChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ConfirmEmailChange
{
    private const INVALID = 'This link is for a different account, or for a change that was replaced or cancelled.';

    /**
     * Make the pending email the account's email. The route checks the link's
     * signature and expiry; this checks it's for this user and their latest request.
     * The link reached that inbox, so the address is verified. Other devices are
     * signed out, and the old address gets a notice.
     *
     * @throws AuthorizationException a link for another account, or an older or cancelled request
     * @throws ValidationException the address is now another account's
     */
    public function handle(User $user, int $id, string $hash, ?PersonalAccessToken $currentToken): User
    {
        if ($user->getKey() !== $id) {
            throw new AuthorizationException(self::INVALID);
        }

        try {
            // Together, on the locked row: a password reset or a cancel at the same moment
            // either happens first (and this link then fails) or after.
            [$locked, $oldEmail] = DB::transaction(function () use ($user, $hash, $currentToken) {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $pending = $locked->pending_email;

                // The token this came with must still exist (a reset meanwhile deletes it).
                if ($pending === null || ! hash_equals(sha1($pending), $hash)
                    || ($currentToken !== null && ! $locked->tokens()->whereKey($currentToken->getKey())->exists())) {
                    throw new AuthorizationException(self::INVALID);
                }

                $oldEmail = $locked->email;
                $locked->forceFill([
                    'email' => $pending,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'previous_email' => $oldEmail,
                ])->save();

                $locked->tokens()
                    ->when($currentToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                    ->delete();

                return [$locked, $oldEmail];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Another account uses that email now.']]);
        }

        // After the commit: nothing is sent for a change that didn't happen.
        Notification::route('mail', $oldEmail)->notify(new EmailChanged($locked->id, $oldEmail, $locked->email));

        return $locked;
    }
}

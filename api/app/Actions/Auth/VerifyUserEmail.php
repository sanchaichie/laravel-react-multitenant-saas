<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class VerifyUserEmail
{
    /**
     * Mark the user's email as verified. The route checks the link's signature and
     * expiry; this checks it's for this user and their current address.
     *
     * @throws AuthorizationException a link for another account or an older address
     */
    public function handle(User $user, int $id, string $hash): void
    {
        if ($user->getKey() !== $id || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new AuthorizationException('This verification link is for a different account or email address.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }
}

<?php

namespace App\Actions\Account;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RevokeDevice
{
    /**
     * Delete one of the user's own API tokens (signing that device out).
     *
     * @throws NotFoundHttpException not one of the user's tokens
     */
    public function handle(User $user, int $tokenId): void
    {
        // A support session isn't one of their devices (hidden from the list too).
        $deleted = $user->tokens()->whereNull('impersonator_id')->whereKey($tokenId)->delete();

        if ($deleted === 0) {
            throw new NotFoundHttpException;
        }
    }
}

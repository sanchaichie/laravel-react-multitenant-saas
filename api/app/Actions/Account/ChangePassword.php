<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class ChangePassword
{
    /**
     * Set a new password (the request checked the current one) and sign out every
     * other device. The token of this request keeps working.
     */
    public function handle(User $user, string $password, ?PersonalAccessToken $currentToken): void
    {
        // Together: never a new password with the other devices still signed in.
        DB::transaction(function () use ($user, $password, $currentToken) {
            // A pending email change goes too: changing the password is how you'd stop
            // someone who had the old one.
            $user->forceFill(['password' => $password, 'remember_token' => null, 'pending_email' => null])->save();

            $user->tokens()
                ->when($currentToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                ->delete();
        });
    }
}

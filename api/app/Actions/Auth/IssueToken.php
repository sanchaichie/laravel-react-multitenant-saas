<?php

namespace App\Actions\Auth;

use App\Models\User;

class IssueToken
{
    /**
     * A new API token for the user, named after the device it was issued to.
     * Every way of logging in goes through here, so later checks (two-factor,
     * suspension) have one place to live.
     */
    public function handle(User $user, ?string $deviceName = null): string
    {
        return $user->createToken($deviceName ?: 'web')->plainTextToken;
    }
}

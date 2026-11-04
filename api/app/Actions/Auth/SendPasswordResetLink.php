<?php

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Password;

class SendPasswordResetLink
{
    /**
     * Email a reset link if an account has this address. The caller answers the same
     * either way (unknown email, throttled, sent) so accounts can't be discovered.
     */
    public function handle(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }
}

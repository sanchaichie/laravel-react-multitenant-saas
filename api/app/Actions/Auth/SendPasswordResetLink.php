<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;

class SendPasswordResetLink
{
    /**
     * Email a reset link if an account has this address. The caller answers the same
     * either way (unknown email, throttled, suspended, sent) so accounts can't be discovered.
     */
    public function handle(string $email): void
    {
        if (User::where('email', mb_strtolower($email))->first()?->isSuspended()) {
            return;
        }

        Password::broker()->sendResetLink(['email' => $email]);
    }
}

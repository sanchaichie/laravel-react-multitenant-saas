<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetUserPassword
{
    public function __construct(private readonly IssueToken $issueToken) {}

    /**
     * Set a new password with a reset token, sign out every device and log in here.
     * Following the emailed link also proves the address, so it counts as verified.
     *
     * @param  array{email: string, token: string, password: string}  $data
     * @return array{user: User, token: string}
     *
     * @throws ValidationException invalid or expired token, or an unknown email
     */
    public function handle(array $data): array
    {
        $resetUser = null;

        $status = Password::broker()->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) use (&$resetUser) {
                // Locked, so an email change confirmed at the same moment waits and then
                // finds its request cancelled (or happened first and this reset fails).
                $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                // A pending email change is cancelled: resetting through this inbox is how
                // the owner takes the account back from someone who knew the password.
                $user->forceFill(['password' => $password, 'remember_token' => null, 'pending_email' => null]);
                if (! $user->hasVerifiedEmail()) {
                    $user->email_verified_at = now();
                }
                $user->save();
                $user->tokens()->delete();
                $resetUser = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || $resetUser === null) {
            // Same message for an unknown email and a bad token.
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return ['user' => $resetUser, 'token' => $this->issueToken->handle($resetUser)];
    }
}

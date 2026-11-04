<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetUserPassword
{
    public function __construct(
        private readonly IssueToken $issueToken,
        private readonly IssueTwoFactorChallenge $issueChallenge,
    ) {}

    /**
     * Set a new password with a reset token, sign out every device and log in here.
     * Following the emailed link also proves the address, so it counts as verified.
     * With two-factor on, the answer is a challenge instead of a token: the inbox
     * alone isn't enough to get in.
     *
     * @param  array{email: string, token: string, password: string}  $data
     * @return array{user: User, token: string}|array{two_factor: true, challenge: string}
     *
     * @throws ValidationException invalid or expired token, an unknown email, or a suspended account
     */
    public function handle(array $data): array
    {
        $resetUser = null;
        $suspended = false;

        // One transaction, so the row lock below lasts until the password, the tokens
        // and the reset token are all changed (the broker opens none), and the
        // two-factor read after it can't be overtaken by a setup confirmed meanwhile.
        // A full closure, not fn (): an arrow function copies $resetUser, and the
        // callback's reference would then never reach this one.
        $status = DB::transaction(function () use ($data, &$resetUser, &$suspended) {
            return Password::broker()->reset(
                ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
                function (User $user, string $password) use (&$resetUser, &$suspended) {
                    // Locked, so an email change confirmed at the same moment waits and then
                    // finds its request cancelled (or happened first and this reset fails).
                    $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    // A link sent before the suspension: used up, nothing changed.
                    if ($user->isSuspended()) {
                        $suspended = true;

                        return;
                    }
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
        });

        // Only reached with a valid link, so it tells nobody else about the account.
        if ($suspended) {
            throw ValidationException::withMessages(['email' => [User::SUSPENDED_MESSAGE]]);
        }

        if ($status !== Password::PASSWORD_RESET || $resetUser === null) {
            // Same message for an unknown email and a bad token.
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        if ($resetUser->hasTwoFactorEnabled()) {
            return ['two_factor' => true, 'challenge' => $this->issueChallenge->handle($resetUser)];
        }

        return ['user' => $resetUser, 'token' => $this->issueToken->handle($resetUser)];
    }
}

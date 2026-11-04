<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginUser
{
    public function __construct(
        private readonly IssueToken $issueToken,
        private readonly IssueTwoFactorChallenge $issueChallenge,
    ) {}

    /**
     * Verify credentials and issue a new API token, or, when two-factor is on, a
     * challenge to swap for one with a code (CompleteTwoFactorChallenge).
     *
     * @param  array{email: string, password: string, device_name?: string|null}  $data
     * @return array{user: User, token: string}|array{two_factor: true, challenge: string}
     *
     * @throws ValidationException
     */
    public function handle(array $data): array
    {
        $user = User::where('email', strtolower($data['email']))->first();

        // Same error for unknown email and wrong password so we don't reveal which accounts exist.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // After the password check: only someone with the right password learns it.
        if ($user->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => [User::SUSPENDED_MESSAGE],
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            return ['two_factor' => true, 'challenge' => $this->issueChallenge->handle($user, $data['device_name'] ?? null)];
        }

        return ['user' => $user, 'token' => $this->issueToken->handle($user, $data['device_name'] ?? null)];
    }
}

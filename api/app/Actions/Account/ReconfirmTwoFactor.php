<?php

namespace App\Actions\Account;

use App\Actions\Auth\CheckTwoFactorCode;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ReconfirmTwoFactor
{
    public function __construct(private readonly CheckTwoFactorCode $checkCode) {}

    /**
     * Mark this token as having just passed a code from the authenticator app
     * (two_factor_at: platform admin routes need a recent one). No recovery codes:
     * new ones can be made with the password alone. The code is used up like at
     * login, and a wrong one counts toward the account's limit.
     *
     * @throws ValidationException two-factor off, too many wrong codes, or a wrong (or reused) code
     * @throws AuthenticationException this token was signed out meanwhile
     */
    public function handle(User $user, string $code, PersonalAccessToken $currentToken): void
    {
        $this->checkCode->run($user, $code, null, function (User $locked) use ($currentToken) {
            // Signed out meanwhile (another device, a reset): nothing to mark.
            $token = $locked->tokens()->whereKey($currentToken->getKey())->first();
            if ($token === null) {
                throw new AuthenticationException;
            }

            $token->forceFill(['two_factor_at' => now()])->save();
        });
    }
}

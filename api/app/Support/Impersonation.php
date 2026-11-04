<?php

namespace App\Support;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Whether this request is a platform admin's read-only "view as user" session
 * (StartImpersonation): the logged-in user is the one being viewed, and the token
 * names an impersonator. Stateless: it reads the current token each time.
 * ReadOnlyImpersonation refuses its writes; NotImpersonating and the resources hide
 * task content and private account data from it.
 */
class Impersonation
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function active(): bool
    {
        return $this->token() !== null;
    }

    /**
     * The "view as user" token of this request, if it is one.
     */
    public function token(): ?PersonalAccessToken
    {
        $user = $this->auth->guard()->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken && $token->isImpersonation() ? $token : null;
    }
}

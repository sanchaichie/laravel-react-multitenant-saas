<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\CompleteTwoFactorChallenge;
use App\Http\Controllers\Api\Concerns\RespondsWithLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use Illuminate\Http\JsonResponse;

/**
 * The second step of logging in (or of a password reset) when two-factor is on.
 */
class TwoFactorChallengeController extends Controller
{
    use RespondsWithLogin;

    public function store(TwoFactorChallengeRequest $request, CompleteTwoFactorChallenge $action): JsonResponse
    {
        return $this->loginResponse($action->handle($request->validated()));
    }
}

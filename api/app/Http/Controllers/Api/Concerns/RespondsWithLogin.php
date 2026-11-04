<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

trait RespondsWithLogin
{
    /**
     * The user and their new token, or, when two-factor is on, the challenge to
     * send to POST /api/two-factor-challenge with a code.
     *
     * @param  array{user: User, token: string}|array{two_factor: true, challenge: string}  $result
     */
    protected function loginResponse(array $result, int $status = 200): JsonResponse
    {
        if (isset($result['challenge'])) {
            return response()->json(['two_factor' => true, 'challenge' => $result['challenge']]);
        }

        return response()->json([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], $status);
    }
}

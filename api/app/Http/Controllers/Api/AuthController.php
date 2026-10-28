<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        return $this->loginResponse($action->handle($request->validated()), 201);
    }

    public function login(LoginRequest $request, LoginUser $action): JsonResponse
    {
        return $this->loginResponse($action->handle($request->validated()));
    }

    // Revokes only this device's token; others stay logged in.
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * @param  array{user: User, token: string}  $result
     */
    private function loginResponse(array $result, int $status = 200): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
        ], $status);
    }
}

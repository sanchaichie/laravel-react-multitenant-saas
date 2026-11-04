<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\ResetUserPassword;
use App\Actions\Auth\SendPasswordResetLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function sendLink(ForgotPasswordRequest $request, SendPasswordResetLink $action): JsonResponse
    {
        $action->handle($request->validated('email'));

        return response()->json([
            'message' => "If an account uses that email, we've sent it a link to reset the password.",
        ]);
    }

    // Logs in, like AuthController::login.
    public function reset(ResetPasswordRequest $request, ResetUserPassword $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json(['user' => new UserResource($result['user']), 'token' => $result['token']]);
    }
}

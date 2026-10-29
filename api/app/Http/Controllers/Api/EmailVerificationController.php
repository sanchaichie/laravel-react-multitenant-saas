<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\VerifyUserEmail;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * The link from the email, posted back by the app. Its signature and expiry are
     * checked by the route (signed:relative).
     */
    public function verify(Request $request, int $id, string $hash, VerifyUserEmail $action): UserResource
    {
        $action->handle($request->user(), $id, $hash);

        return new UserResource($request->user());
    }

    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email is already verified.', 'email_verified' => true]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'We sent a new verification link to your email.', 'email_verified' => false], 202);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\ConfirmTwoFactor;
use App\Actions\Account\DisableTwoFactor;
use App\Actions\Account\RegenerateRecoveryCodes;
use App\Actions\Account\RevertTwoFactor;
use App\Actions\Account\StartTwoFactorSetup;
use App\Http\Controllers\Api\Concerns\UsesCurrentToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmTwoFactorRequest;
use App\Http\Requests\Account\DisableTwoFactorRequest;
use App\Http\Requests\Account\RegenerateRecoveryCodesRequest;
use App\Http\Requests\Account\StartTwoFactorRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Two-factor authentication on the logged-in user's own account.
 */
class TwoFactorController extends Controller
{
    use UsesCurrentToken;

    public function store(StartTwoFactorRequest $request, StartTwoFactorSetup $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($request->user())]);
    }

    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactor $action): JsonResponse
    {
        $codes = $action->handle($request->user(), $request->validated('code'), $this->currentToken($request));

        return response()->json(['data' => [
            'recovery_codes' => $codes,
            'user' => new UserResource($request->user()->refresh()),
        ]]);
    }

    public function recoveryCodes(RegenerateRecoveryCodesRequest $request, RegenerateRecoveryCodes $action): JsonResponse
    {
        return response()->json(['data' => ['recovery_codes' => $action->handle($request->user(), $request->validated('code'))]]);
    }

    public function destroy(DisableTwoFactorRequest $request, DisableTwoFactor $action): UserResource
    {
        $action->handle($request->user(), $request->validated('code'), $request->validated('recovery_code'));

        return new UserResource($request->user()->refresh());
    }

    /**
     * The "this wasn't me" link from the "two-factor was turned on" email (no login).
     */
    public function revert(string $id, string $at, RevertTwoFactor $action): JsonResponse
    {
        $action->handle((int) $id, (int) $at);

        return response()->json([
            'message' => 'Two-factor authentication is off again, and every device was signed out. Check your email for a link to set a new password.',
        ]);
    }
}

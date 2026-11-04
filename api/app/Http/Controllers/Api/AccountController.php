<?php

namespace App\Http\Controllers\Api;

use App\Actions\Account\ChangeEmail;
use App\Actions\Account\ChangePassword;
use App\Actions\Account\ConfirmEmailChange;
use App\Actions\Account\DeleteAccount;
use App\Actions\Account\RevertEmailChange;
use App\Actions\Account\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangeEmailRequest;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The logged-in user's own account. Not tied to an organization.
 */
class AccountController extends Controller
{
    public function update(UpdateProfileRequest $request, UpdateProfile $action): UserResource
    {
        return new UserResource($action->handle($request->user(), $request->validated()));
    }

    public function updateEmail(ChangeEmailRequest $request, ChangeEmail $action): UserResource
    {
        return new UserResource($action->handle($request->user(), $request->validated('email')));
    }

    /**
     * The link from the confirmation email, posted back by the app. Its signature and
     * expiry are checked by the route (signed:relative).
     */
    public function confirmEmail(Request $request, int $id, string $hash, ConfirmEmailChange $action): UserResource
    {
        return new UserResource($action->handle($request->user(), $id, $hash, $this->currentToken($request)));
    }

    /**
     * The undo link from the "email was changed" notice: no login (the owner may be
     * locked out). The route checks the signature and expiry.
     */
    public function revertEmail(string $id, string $old, string $new, RevertEmailChange $action): JsonResponse
    {
        $action->handle((int) $id, $old, $new);

        return response()->json([
            'message' => 'Your old email address is back, and every device was signed out. Check it for a link to set a new password.',
        ]);
    }

    public function cancelEmailChange(Request $request): UserResource
    {
        $request->user()->forceFill(['pending_email' => null])->save();

        return new UserResource($request->user());
    }

    public function updatePassword(ChangePasswordRequest $request, ChangePassword $action): Response
    {
        $action->handle($request->user(), $request->validated('password'), $this->currentToken($request));

        return response()->noContent();
    }

    public function destroy(DeleteAccountRequest $request, DeleteAccount $action): Response
    {
        $action->handle($request->user());

        return response()->noContent();
    }

    private function currentToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        // A saved token only (Sanctum::actingAs in tests gives an unsaved stand-in).
        return $token instanceof PersonalAccessToken && $token->exists ? $token : null;
    }
}

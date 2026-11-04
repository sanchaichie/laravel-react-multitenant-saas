<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\StartImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminActionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Platform admin: open a read-only "view as user" session, with a reason. Access is
 * the route's platform-admin:action middleware. The app keeps the admin's own token
 * aside and loads /api/me with this one.
 */
class ImpersonationController extends Controller
{
    public function store(AdminActionRequest $request, User $user, StartImpersonation $action): JsonResponse
    {
        $session = $action->handle($request->user(), $user, $request->reason());

        return response()->json([
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
        ], 201);
    }
}

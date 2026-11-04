<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ResetUserTwoFactor;
use App\Actions\Admin\ShowUser;
use App\Actions\Admin\SignOutUser;
use App\Actions\Admin\SuspendUser;
use App\Actions\Admin\UnsuspendUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminActionRequest;
use App\Http\Resources\Admin\AdminUserDetailResource;
use App\Models\User;

/**
 * Platform admin: act on a user, with a reason. Access is the route's
 * platform-admin:action middleware. Each returns the user as the detail page shows
 * it (without a second "viewed" entry: the action has its own).
 */
class UserActionController extends Controller
{
    public function resetTwoFactor(AdminActionRequest $request, User $user, ResetUserTwoFactor $action, ShowUser $show): AdminUserDetailResource
    {
        return $this->detail($action->handle($user, $request->reason()), $show);
    }

    public function signOut(AdminActionRequest $request, User $user, SignOutUser $action, ShowUser $show): AdminUserDetailResource
    {
        return $this->detail($action->handle($user, $request->reason()), $show);
    }

    public function suspend(AdminActionRequest $request, User $user, SuspendUser $action, ShowUser $show): AdminUserDetailResource
    {
        return $this->detail($action->handle($user, $request->reason()), $show);
    }

    public function unsuspend(AdminActionRequest $request, User $user, UnsuspendUser $action, ShowUser $show): AdminUserDetailResource
    {
        return $this->detail($action->handle($user, $request->reason()), $show);
    }

    private function detail(User $user, ShowUser $show): AdminUserDetailResource
    {
        return new AdminUserDetailResource($show->handle($user->fresh(), record: false));
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\SearchUsers;
use App\Actions\Admin\ShowUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminSearchRequest;
use App\Http\Resources\Admin\AdminUserDetailResource;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform admin: look up any user. Access is the route's platform-admin middleware.
 */
class UserController extends Controller
{
    public function index(AdminSearchRequest $request, SearchUsers $action): AnonymousResourceCollection
    {
        return AdminUserResource::collection($action->handle($request->search()));
    }

    public function show(User $user, ShowUser $action): AdminUserDetailResource
    {
        return new AdminUserDetailResource($action->handle($user));
    }
}

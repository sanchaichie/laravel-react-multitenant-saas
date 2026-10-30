<?php

namespace App\Http\Controllers\Api;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Roles of the current organization. {role} binds through the organization scope:
 * another organization's role is a 404.
 */
class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()->withCount('memberships')->orderByDesc('is_owner')->orderBy('id')->get();

        return RoleResource::collection($roles);
    }

    public function store(RoleRequest $request, CreateRole $action): JsonResponse
    {
        Gate::authorize('create', [Role::class, $request->permissions()]);

        $role = $action->handle($request->validated('name'), $request->permissions());

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(RoleRequest $request, Role $role, UpdateRole $action): RoleResource
    {
        Gate::authorize('update', [$role, $request->permissions()]);

        return new RoleResource($action->handle($role, $request->validated('name'), $request->permissions()));
    }

    public function destroy(Role $role, DeleteRole $action): Response
    {
        Gate::authorize('delete', $role);

        $action->handle($role);

        return response()->noContent();
    }
}

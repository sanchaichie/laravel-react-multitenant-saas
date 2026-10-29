<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\InviteMember;
use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\OrganizationInvitation;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Pending invitations of the current organization (BelongsToOrganization scopes
 * every query, so another organization's invitation is a 404).
 */
class InvitationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', OrganizationInvitation::class);

        $invitations = OrganizationInvitation::pending()->with('inviter')->latest()->orderByDesc('id')->get();

        // The roles the requesting user may invite with (the policy's answer), for the form.
        $invitable = array_values(array_filter(
            OrganizationRole::cases(),
            fn (OrganizationRole $role) => Gate::allows('create', [OrganizationInvitation::class, $role]),
        ));

        return InvitationResource::collection($invitations)
            ->additional(['meta' => ['invitable_roles' => array_map(fn (OrganizationRole $role) => $role->value, $invitable)]]);
    }

    public function store(StoreInvitationRequest $request, InviteMember $action, CurrentOrganization $currentOrganization): JsonResponse
    {
        Gate::authorize('create', [OrganizationInvitation::class, $request->role()]);

        $invitation = $action->handle(
            $request->user(),
            $currentOrganization->get(),
            $request->validated('email'),
            $request->role(),
        );

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    public function destroy(int $invitation): Response
    {
        $invitation = OrganizationInvitation::pending()->findOrFail($invitation);
        Gate::authorize('delete', $invitation);

        $invitation->delete();

        return response()->noContent();
    }
}

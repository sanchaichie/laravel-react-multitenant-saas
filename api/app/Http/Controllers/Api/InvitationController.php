<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\InviteMember;
use App\Actions\Organizations\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\RoleSummaryResource;
use App\Models\OrganizationInvitation;
use App\Models\Role;
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
    public function index(CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', OrganizationInvitation::class);

        $invitations = OrganizationInvitation::pending()->with(['inviter', 'role'])->latest()->orderByDesc('id')->get();

        // The roles the requesting user may invite with (the policy's answer), for the form.
        $invitable = $currentOrganization->roles()
            ->filter(fn (Role $role) => Gate::allows('create', [OrganizationInvitation::class, $role]))
            ->values();

        // The form starts on the least powerful of them (fewest permissions, then the
        // oldest), so pressing Invite without looking never grants more than needed.
        $default = $invitable->sortBy([fn (Role $a, Role $b) => [$a->is_owner, $a->grants()->count(), $a->id] <=> [$b->is_owner, $b->grants()->count(), $b->id]])->first();

        return InvitationResource::collection($invitations)->additional(['meta' => [
            'invitable_roles' => RoleSummaryResource::collection($invitable),
            'default_role_id' => $default?->id,
        ]]);
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

        return (new InvitationResource($invitation->load('role')))->response()->setStatusCode(201);
    }

    public function destroy(int $invitation, RevokeInvitation $action): Response
    {
        $invitation = OrganizationInvitation::pending()->findOrFail($invitation);
        Gate::authorize('delete', $invitation);

        $action->handle($invitation);

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Membership;
use App\Support\CurrentOrganization;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Members of the current organization. {member} is a user id, always looked up
 * among the current organization's members: anyone else is a 404.
 * CurrentOrganization is injected per action, not in the constructor: the router
 * reuses controller instances (tests, Octane), which would keep the first request's.
 */
class MemberController extends Controller
{
    public function index(CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Membership::class);

        $members = $currentOrganization->get()->members()->orderBy('name')->orderBy('users.id')->get();

        return MemberResource::collection($members);
    }

    public function update(UpdateMemberRequest $request, int $member, ChangeMemberRole $action, CurrentOrganization $currentOrganization): MemberResource
    {
        $organization = $currentOrganization->get();
        $member = $organization->members()->findOrFail($member);
        $role = $request->role();
        Gate::authorize('update', [$member->membership, $role]);

        return new MemberResource($action->handle($organization, $member, $role));
    }

    // Removing someone, or leaving when {member} is yourself.
    public function destroy(int $member, RemoveMember $action, CurrentOrganization $currentOrganization): Response
    {
        $organization = $currentOrganization->get();
        $member = $organization->members()->findOrFail($member);
        Gate::authorize('delete', $member->membership);

        $action->handle($organization, $member);

        return response()->noContent();
    }
}

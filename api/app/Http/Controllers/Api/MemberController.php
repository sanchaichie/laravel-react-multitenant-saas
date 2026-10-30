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
 */
class MemberController extends Controller
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Membership::class);

        $members = $this->currentOrganization->get()->members()->orderBy('name')->orderBy('users.id')->get();

        return MemberResource::collection($members);
    }

    public function update(UpdateMemberRequest $request, int $member, ChangeMemberRole $action): MemberResource
    {
        $organization = $this->currentOrganization->get();
        $member = $organization->members()->findOrFail($member);
        $role = $request->role();
        Gate::authorize('update', [$member->membership, $role]);

        return new MemberResource($action->handle($organization, $member, $role));
    }

    // Removing someone, or leaving when {member} is yourself.
    public function destroy(int $member, RemoveMember $action): Response
    {
        $organization = $this->currentOrganization->get();
        $member = $organization->members()->findOrFail($member);
        Gate::authorize('delete', $member->membership);

        $action->handle($organization, $member);

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationPreviewResource;
use App\Http\Resources\OrganizationResource;
use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;

/**
 * The invitation link (/join/{token} in the app). Any logged-in user holding the
 * token reaches it, from whatever current organization; the invitation must be
 * usable and for their email address (AcceptInvitation::ensureUsableBy).
 */
class JoinController extends Controller
{
    public function show(Request $request, string $token): InvitationPreviewResource
    {
        $invitation = OrganizationInvitation::findByToken($token);
        AcceptInvitation::ensureUsableBy($invitation, $request->user());

        return new InvitationPreviewResource($invitation->load(['organization', 'inviter']));
    }

    public function store(Request $request, string $token, AcceptInvitation $action): OrganizationResource
    {
        return new OrganizationResource($action->handle($request->user(), $token));
    }
}

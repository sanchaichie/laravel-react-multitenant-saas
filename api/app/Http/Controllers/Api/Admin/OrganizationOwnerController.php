<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\MakeMemberOwner;
use App\Actions\Admin\ShowOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MakeOwnerRequest;
use App\Http\Resources\Admin\AdminOrganizationDetailResource;
use App\Models\Organization;

/**
 * Platform admin: make an existing member Owner, with a reason. Access is the route's
 * platform-admin:action middleware. Returns the organization as the detail page shows it.
 */
class OrganizationOwnerController extends Controller
{
    public function store(MakeOwnerRequest $request, Organization $organization, MakeMemberOwner $action, ShowOrganization $show): AdminOrganizationDetailResource
    {
        $action->handle($organization, $request->integer('user_id'), $request->reason());

        return new AdminOrganizationDetailResource($show->handle($organization, record: false));
    }
}

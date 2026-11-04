<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\SearchOrganizations;
use App\Actions\Admin\ShowOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminSearchRequest;
use App\Http\Resources\Admin\AdminOrganizationDetailResource;
use App\Http\Resources\Admin\AdminOrganizationResource;
use App\Models\Organization;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform admin: look up any organization. Access is the route's platform-admin middleware.
 */
class OrganizationController extends Controller
{
    public function index(AdminSearchRequest $request, SearchOrganizations $action): AnonymousResourceCollection
    {
        return AdminOrganizationResource::collection($action->handle($request->search()));
    }

    public function show(Organization $organization, ShowOrganization $action): AdminOrganizationDetailResource
    {
        return new AdminOrganizationDetailResource($action->handle($organization));
    }
}

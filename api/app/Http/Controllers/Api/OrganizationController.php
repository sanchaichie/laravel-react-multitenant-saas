<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\CreateOrganization;
use App\Actions\Organizations\SwitchOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    // The user's own organizations only, with their role in each.
    public function index(Request $request, CurrentOrganization $currentOrganization): AnonymousResourceCollection
    {
        $organizations = $request->user()->organizations()->orderBy('name')->orderBy('organizations.id')->get();

        return OrganizationResource::collection($organizations)
            ->additional(['meta' => ['current_organization_id' => $currentOrganization->id()]]);
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $action): JsonResponse
    {
        $organization = $action->handle($request->user(), $request->validated('name'));

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    public function switch(Request $request, int $organization, SwitchOrganization $action): OrganizationResource
    {
        return new OrganizationResource($action->handle($request->user(), $organization));
    }
}

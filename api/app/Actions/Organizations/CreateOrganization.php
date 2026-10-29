<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * Create an organization owned by the user and make it their current one.
     * Returns it with the user's membership (role) loaded.
     */
    public function handle(User $owner, string $name): Organization
    {
        $organization = DB::transaction(function () use ($owner, $name) {
            $organization = Organization::create(['name' => $name]);
            $organization->members()->attach($owner, ['role' => OrganizationRole::Owner]);

            $owner->forceFill(['current_organization_id' => $organization->id])->save();

            return $organization;
        });

        $this->currentOrganization->forget();

        return $owner->organizations()->findOrFail($organization->id);
    }
}

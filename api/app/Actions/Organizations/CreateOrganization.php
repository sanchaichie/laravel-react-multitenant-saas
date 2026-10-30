<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Actions\Roles\CreateDefaultRoles;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly CreateDefaultRoles $createDefaultRoles,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * Create an organization owned by the user and make it their current one.
     * Returns it with the user's membership (role) loaded.
     */
    public function handle(User $owner, string $name): Organization
    {
        $organization = DB::transaction(function () use ($owner, $name) {
            $organization = Organization::create(['name' => $name]);
            $roles = $this->createDefaultRoles->handle($organization);
            $organization->members()->attach($owner, ['role_id' => $roles[DefaultRole::Owner->value]->id]);

            $owner->forceFill(['current_organization_id' => $organization->id])->save();

            $this->recordAudit->handle(
                AuditAction::OrganizationCreated,
                ['type' => 'organization', 'id' => $organization->id, 'label' => $organization->name],
                organization: $organization,
                // Registering creates one before the user is logged in.
                actor: $owner,
            );

            return $organization;
        });

        $this->currentOrganization->forget();

        return $owner->organizations()->findOrFail($organization->id);
    }
}

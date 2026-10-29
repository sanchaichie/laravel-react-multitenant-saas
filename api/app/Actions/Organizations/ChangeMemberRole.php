<?php

namespace App\Actions\Organizations;

use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class ChangeMemberRole
{
    use EnsuresAnOwnerRemains;

    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * @param  User  $member  loaded through $organization->members() (membership attached)
     * @return User the member, re-loaded with the new role
     */
    public function handle(Organization $organization, User $member, OrganizationRole $role): User
    {
        DB::transaction(function () use ($organization, $member, $role) {
            $this->lockOrganization($organization);

            if ($this->lockedRole($organization, $member) === OrganizationRole::Owner && $role !== OrganizationRole::Owner) {
                $this->ensureAnotherOwner($organization, $member, 'role');
            }

            $organization->members()->updateExistingPivot($member->id, ['role' => $role]);
        });

        // The acting user may have changed their own role.
        $this->currentOrganization->forget();

        return $organization->members()->findOrFail($member->id);
    }
}

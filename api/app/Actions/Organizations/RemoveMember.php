<?php

namespace App\Actions\Organizations;

use App\Actions\Organizations\Concerns\EnsuresAnOwnerRemains;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    use EnsuresAnOwnerRemains;

    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * Remove a member (or let them leave). If this was their current organization,
     * move them to another one they belong to (first by name), or to none.
     *
     * @param  User  $member  loaded through $organization->members() (membership attached)
     */
    public function handle(Organization $organization, User $member): void
    {
        DB::transaction(function () use ($organization, $member) {
            $this->lockOrganization($organization);

            if ($this->lockedRole($organization, $member) === OrganizationRole::Owner) {
                $this->ensureAnotherOwner($organization, $member, 'member');
            }

            $organization->members()->detach($member->id);

            if ($member->current_organization_id === $organization->id) {
                $member->forceFill([
                    'current_organization_id' => $member->organizations()->orderBy('organizations.name')->orderBy('organizations.id')->first()?->id,
                ])->save();
            }
        });

        $this->currentOrganization->forget();
    }
}

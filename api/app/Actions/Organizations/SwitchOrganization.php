<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SwitchOrganization
{
    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * Make one of the user's organizations their current one. Returns it with the
     * membership (role) loaded.
     *
     * @throws ModelNotFoundException when the user is not a member (also when it
     *                                doesn't exist), so callers can't tell the two apart.
     */
    public function handle(User $user, int $organizationId): Organization
    {
        $organization = $user->organizations()->findOrFail($organizationId);

        $user->forceFill(['current_organization_id' => $organization->id])->save();
        $this->currentOrganization->forget();

        return $organization;
    }
}

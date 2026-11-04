<?php

namespace App\Actions\Organizations;

use App\Actions\Admin\RecordImpersonatedView;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\Impersonation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SwitchOrganization
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly Impersonation $impersonation,
        private readonly RecordImpersonatedView $recordImpersonatedView,
    ) {}

    /**
     * Make one of the user's organizations their current one. Returns it with the
     * membership (role) loaded. In a platform admin's "view as user" session only the
     * session moves (and the organization is told), never the user's own.
     *
     * @throws ModelNotFoundException when the user is not a member (also when it
     *                                doesn't exist), so callers can't tell the two apart.
     */
    public function handle(User $user, int $organizationId): Organization
    {
        $organization = $user->organizations()->findOrFail($organizationId);

        if ($token = $this->impersonation->token()) {
            $this->recordImpersonatedView->handle($token, $organization, $user);
        } else {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }
        $this->currentOrganization->forget();

        return $organization;
    }
}

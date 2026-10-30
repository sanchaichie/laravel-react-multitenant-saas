<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A row in organization_user: one user's membership (and role) in one organization.
 *
 * Deliberately not BelongsToOrganization: a user's memberships span organizations
 * (the switcher lists them all). Reach another organization's members only through
 * the current one, app(CurrentOrganization::class)->get()->members(), never with
 * an organization id from the request.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int $role_id
 * @property-read Role $role
 */
class Membership extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
        ];
    }

    /**
     * Without the organization scope: this membership may be in another organization
     * than the current one (the switcher lists them all), and the composite foreign
     * key keeps its role in the membership's own organization.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScope(OrganizationScope::class);
    }
}

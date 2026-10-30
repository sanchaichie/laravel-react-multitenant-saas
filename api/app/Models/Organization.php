<?php

namespace App\Models;

use App\Enums\DefaultRole;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string|null $ai_monthly_budget_usd Own monthly AI budget (decimal string); null uses the default (AiBudget).
 * @property-read Membership|null $membership Set when loaded through User::organizations().
 */
#[Fillable(['name'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Not fillable: only UpdateAiBudget sets ai_monthly_budget_usd.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_monthly_budget_usd' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * This organization's roles, whatever the current organization (it's already
     * limited to this one). For seeding and tests: requests use CurrentOrganization::roles().
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * One of this organization's roles: a default one by its original name (seeding
     * and tests only; an organization may rename them), or check that a Role is ours.
     */
    public function role(DefaultRole|Role $role): Role
    {
        if ($role instanceof Role) {
            if ($role->organization_id !== $this->id) {
                throw new LogicException('That role belongs to another organization.');
            }

            return $role;
        }

        return $role->isOwner()
            ? $this->roles()->where('is_owner', true)->sole()
            : $this->roles()->where('name', $role->label())->sole();
    }
}

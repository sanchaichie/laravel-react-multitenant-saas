<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A role in one organization; memberships and invitations point at one. Check
 * what it allows with can() and includes(), never by its name.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property bool $is_owner Only set by CreateDefaultRoles.
 * @property Collection<int, Permission> $permissions What's stored; use grants() for checks.
 */
#[Fillable(['name', 'permissions'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use BelongsToOrganization, HasFactory;

    /** So a script can't create endless rows. */
    public const MAX_PER_ORGANIZATION = 50;

    protected $attributes = [
        'is_owner' => false,
        'permissions' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'is_owner' => 'boolean',
            'permissions' => AsEnumCollection::of(Permission::class),
        ];
    }

    /**
     * Everything this role allows: every permission for the owner role.
     *
     * @return Collection<int, Permission>
     */
    public function grants(): Collection
    {
        return $this->is_owner ? collect(Permission::cases()) : $this->permissions;
    }

    public function can(Permission $permission): bool
    {
        return $this->grants()->contains($permission);
    }

    /**
     * Whether this role allows every one of $permissions.
     *
     * @param  iterable<Permission>  $permissions
     */
    public function covers(iterable $permissions): bool
    {
        return collect($permissions)->every(fn (Permission $permission) => $this->can($permission));
    }

    /**
     * Whether this role allows everything $other does. You may only act on, or
     * give, roles you include. Only the owner role includes the owner role.
     */
    public function includes(Role $other): bool
    {
        return $other->is_owner ? $this->is_owner : $this->covers($other->grants());
    }

    /**
     * Whether this role allows everything $other does, and more: what editing or
     * deleting a role takes (not your own, not a peer's).
     */
    public function includesStrictly(Role $other): bool
    {
        return $this->includes($other) && ! $other->includes($this);
    }

    /**
     * Memberships aren't BelongsToOrganization (a user's span organizations); these
     * are this role's, so in its organization.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }
}

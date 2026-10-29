<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int|null $current_organization_id Only changed by SwitchOrganization / CreateOrganization.
 *                                             Not proof of access: read it through activeOrganization()
 *                                             or App\Support\CurrentOrganization, which check membership.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Stored in lowercase whatever the write path, so login (which lowercases) finds it
     * and the case-sensitive unique index can't hold two spellings of one address.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this, Membership, 'membership'>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The current organization, but only while the user is still a member of it,
     * with the membership (role) loaded.
     */
    public function activeOrganization(): ?Organization
    {
        if ($this->current_organization_id === null) {
            return null;
        }

        return $this->organizations()->whereKey($this->current_organization_id)->first();
    }

    public function isMemberOf(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->organizations()->whereKey($id)->exists();
    }
}

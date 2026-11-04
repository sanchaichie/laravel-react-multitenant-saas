<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int|null $current_organization_id Only changed by SwitchOrganization / CreateOrganization.
 *                                             Not proof of access: read it through activeOrganization()
 *                                             or App\Support\CurrentOrganization, which check membership.
 * @property string|null $pending_email A requested email change, until the new address confirms it (never fillable).
 * @property string|null $previous_email The email before the last confirmed change, for its undo link (never fillable).
 * @property Carbon|null $email_changed_at When that change was confirmed (never fillable).
 * @property string|null $two_factor_secret The authenticator app's secret (encrypted). Two-factor is only on once
 *                                          two_factor_confirmed_at is set; a secret alone is an unfinished setup.
 * @property list<string>|null $two_factor_recovery_codes sha256 hashes of the unused recovery codes.
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_step The time step of the last code accepted, so no code works twice.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_step'])]
class User extends Authenticatable implements MustVerifyEmail
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
            'email_changed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
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
            ->withPivot('role_id')
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

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isMemberOf(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->organizations()->whereKey($id)->exists();
    }

    /**
     * The verification link points at the React app (App\Notifications\VerifyEmail).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    /**
     * The reset link points at the React app (App\Notifications\ResetPassword).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }
}

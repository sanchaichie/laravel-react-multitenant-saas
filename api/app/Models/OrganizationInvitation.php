<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to join an organization, by email. The first tenant-owned model:
 * every query is limited to the current organization, except findByToken().
 *
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property int|null $role_id Null once its role is deleted: then it can't be accepted.
 * @property-read Role|null $role
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
#[Fillable(['email', 'role_id', 'token_hash', 'invited_by', 'expires_at'])]
#[Hidden(['token_hash'])]
class OrganizationInvitation extends Model
{
    use BelongsToOrganization, Prunable;

    public const LIFETIME_DAYS = 7;

    /** Accepted or expired invitations are deleted this many days later (model:prune, daily). */
    public const PRUNE_AFTER_DAYS = 30;

    protected function casts(): array
    {
        return [
            'role_id' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Look an invitation up by the token from its email link, in any organization.
     *
     * The one place that bypasses the organization scope: the person joining is
     * (by definition) not acting in the inviting organization yet. Holding the
     * token is the proof; callers still check the email (AcceptInvitation).
     */
    public static function findByToken(string $token, bool $lock = false): ?self
    {
        return static::withoutGlobalScope(OrganizationScope::class)
            ->where('token_hash', static::hashToken($token))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * What model:prune deletes, in every organization (it runs without one).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $before = now()->subDays(self::PRUNE_AFTER_DAYS);

        return static::withoutGlobalScope(OrganizationScope::class)
            ->where(fn (Builder $query) => $query->where('accepted_at', '<', $before)->orWhere('expires_at', '<', $before));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->role_id !== null && ! $this->isExpired();
    }

    /**
     * Without the organization scope: the join page shows an invitation to someone
     * acting in another organization. The composite foreign key keeps the role in
     * the invitation's organization.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}

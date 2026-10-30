<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One audit log entry, written by RecordAudit (forceFill: nothing is fillable).
 * Entries are never changed; old ones are deleted by model:prune after
 * config('audit.retention_days').
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $actor_id
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label The subject's name when it happened.
 * @property array<string, array{old: mixed, new: mixed}>|null $changes
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use BelongsToOrganization, MassPrunable;

    public const PER_PAGE = 25;

    // Append-only: no updated_at.
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted one by one.'));
    }

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'subject_id' => 'integer',
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Old entries of every organization: the scheduler has no current organization,
     * so the scope would match nothing.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = (int) config('audit.retention_days');

        return static::withoutGlobalScope(OrganizationScope::class)
            ->when(
                $days > 0,
                fn (Builder $query) => $query->where('created_at', '<', now()->subDays($days)),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );
    }

    /**
     * The list's filters (already validated by ListAuditLogsRequest). Dates are whole
     * days in app.timezone.
     *
     * @param  Builder<self>  $query
     * @param  array{action?: string, actor?: int|string, from?: string, to?: string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($filters['actor'] ?? null, fn (Builder $q, int|string $actor) => $q->where('actor_id', (int) $actor))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskSummaryStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Impersonation;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A task in an organization: the reference tenant-owned model (see the
 * tenant-feature skill). created_by is set by CreateTask, never from input.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property Carbon|null $due_date
 * @property int|null $assignee_id A member of the same organization (checked on write).
 * @property int|null $created_by
 */
#[Fillable(['title', 'description', 'status', 'priority', 'due_date', 'assignee_id'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use Auditable, BelongsToOrganization, HasFactory;

    public const PER_PAGE = 20;

    /** The entry's actor is who created it. */
    protected array $auditExclude = ['created_by'];

    protected $attributes = [
        'status' => 'todo',
        'priority' => 'medium',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date:Y-m-d',
            // Ints even when set from input like "5": TaskPolicy compares them strictly.
            'assignee_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function auditLabel(): ?string
    {
        return $this->title;
    }

    /**
     * The assignee by name, so the entry still reads after they leave.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function auditChanges(array $changes): array
    {
        if (! isset($changes['assignee_id'])) {
            return $changes;
        }

        ['old' => $old, 'new' => $new] = $changes['assignee_id'];
        $names = User::query()->whereKey(array_filter([$old, $new]))->pluck('name', 'id');
        $person = fn (?int $id) => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? null];

        unset($changes['assignee_id']);

        return [...$changes, 'assignee' => ['old' => $person($old), 'new' => $person($new)]];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<TaskSummary, $this>
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(TaskSummary::class);
    }

    /**
     * The newest summary, whatever its status.
     *
     * @return HasOne<TaskSummary, $this>
     */
    public function latestSummary(): HasOne
    {
        return $this->hasOne(TaskSummary::class)->latestOfMany();
    }

    /**
     * Every member's chat about this task: only for cleanup and tests. Read one
     * person's with TaskChatMessage::conversation().
     *
     * @return HasMany<TaskChatMessage, $this>
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(TaskChatMessage::class);
    }

    /**
     * The task's summary as shown, until a newer one is approved (at most one, by index).
     *
     * @return HasOne<TaskSummary, $this>
     */
    public function approvedSummary(): HasOne
    {
        return $this->hasOne(TaskSummary::class)->where('status', TaskSummaryStatus::Approved);
    }

    /**
     * Due before today (the API's date, app.timezone) and not done. The same rule
     * as scopeOverdue(), so the list's filter and the label agree.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->status !== TaskStatus::Done
            && $this->due_date->lt(today());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('due_date', '<', today()->toDateString())
            ->where('status', '!=', TaskStatus::Done);
    }

    /**
     * The list's filters (already validated by ListTasksRequest).
     *
     * @param  Builder<self>  $query
     * @param  array{status?: string, priority?: string, assignee?: string, overdue?: bool, search?: string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters, User $me): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('priority', $priority))
            ->when($filters['assignee'] ?? null, fn (Builder $q, string $assignee) => match ($assignee) {
                'me' => $q->where('assignee_id', $me->id),
                'none' => $q->whereNull('assignee_id'),
                default => $q->where('assignee_id', (int) $assignee),
            })
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q->overdue())
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->search($search));
    }

    /**
     * Title or description contains $search, case-insensitive. % and _ are matched
     * literally (escaped with \, Postgres' default LIKE escape). Only the title in a
     * platform admin's "view as user" session: a search would otherwise tell what
     * the hidden descriptions say.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $search): void
    {
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        if (app(Impersonation::class)->active()) {
            $query->where('title', 'ilike', $pattern);

            return;
        }

        $query->where(fn (Builder $q) => $q->where('title', 'ilike', $pattern)->orWhere('description', 'ilike', $pattern));
    }

    /**
     * newest (default), due (soonest first, no date last) or priority (high first).
     * Ties break on id, so pages never overlap.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSorted(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'due' => $query->orderByRaw('due_date asc nulls last')->orderBy('id'),
            'priority' => $query
                ->orderByRaw(
                    'case priority '.implode(' ', array_fill(0, count(TaskPriority::cases()), 'when ? then ?')).' else 0 end desc',
                    collect(TaskPriority::cases())->flatMap(fn (TaskPriority $p) => [$p->value, $p->rank()])->all(),
                )
                ->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}

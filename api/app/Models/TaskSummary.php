<?php

namespace App\Models;

use App\Enums\TaskSummaryStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An AI-generated summary of a task. Starts pending, becomes a draft when the job
 * finishes, and is only shown as the task's summary once someone approves it. All
 * columns are set by the actions and the job (no mass assignment).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $task_id
 * @property TaskSummaryStatus $status
 * @property string|null $summary
 * @property list<string>|null $key_points
 * @property string|null $model
 * @property string|null $error
 * @property string|null $source_hash
 * @property int|null $requested_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 */
class TaskSummary extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'status' => TaskSummaryStatus::class,
            'key_points' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

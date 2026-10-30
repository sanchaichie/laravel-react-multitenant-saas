<?php

namespace App\Actions\TaskSummaries;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\TaskSummaryStatus;
use App\Models\Task;
use App\Models\TaskSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReviewTaskSummary
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * Approve or reject a draft. Approving only marks it approved (and retires the
     * previously approved one); it never changes the task. An approval may carry the
     * reviewer's edits (validated by ApproveTaskSummaryRequest); a rejection ignores them.
     *
     * @param  array{summary?: string, key_points?: list<string>}  $edits
     */
    public function handle(TaskSummary $summary, User $reviewer, bool $approve, array $edits = []): TaskSummary
    {
        DB::transaction(function () use ($summary, $reviewer, $approve, $edits) {
            // Lock the task first, as RequestTaskSummary does, so reviews and requests
            // of its summaries run one at a time.
            $task = Task::query()->whereKey($summary->task_id)->lockForUpdate()->firstOrFail();
            $locked = TaskSummary::query()->whereKey($summary->id)->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === TaskSummaryStatus::Draft, 409, 'Only a draft summary can be reviewed.');

            if ($approve) {
                TaskSummary::query()
                    ->where('task_id', $locked->task_id)
                    ->where('status', TaskSummaryStatus::Approved)
                    ->update(['status' => TaskSummaryStatus::Rejected, 'updated_at' => now()]);
            }

            $edits = $approve ? array_intersect_key($edits, array_flip(['summary', 'key_points'])) : [];
            if (isset($edits['key_points'])) {
                $edits['key_points'] = array_values($edits['key_points']);
            }
            $edited = collect($edits)->contains(fn ($value, string $field) => $value !== $locked->{$field});

            $locked->forceFill([
                ...$edits,
                'status' => $approve ? TaskSummaryStatus::Approved : TaskSummaryStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $this->recordAudit->handle(
                $approve ? AuditAction::SummaryApproved : AuditAction::SummaryRejected,
                ['type' => 'task', 'id' => $task->id, 'label' => $task->title],
                // Only whether it was edited: the summary text stays out of the log.
                metadata: ['summary_id' => $locked->id, ...($edited ? ['edited' => true] : [])],
                actor: $reviewer,
            );
        });

        return $summary->fresh(['requester', 'reviewer']);
    }
}

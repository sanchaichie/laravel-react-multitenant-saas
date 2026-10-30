<?php

namespace App\Actions\TaskSummaries;

use App\Actions\Audit\RecordAudit;
use App\Ai\AiBudget;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Enums\AuditAction;
use App\Enums\TaskSummaryStatus;
use App\Jobs\GenerateTaskSummary;
use App\Models\Task;
use App\Models\TaskSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RequestTaskSummary
{
    /**
     * A pending summary older than this is treated as lost (worker down, job gone).
     * Longer than a job can keep trying (OrganizationJob::retryUntil(), 20 minutes),
     * so a job still running is never replaced.
     */
    public const STALE_AFTER_MINUTES = 30;

    public function __construct(
        private readonly RecordAudit $recordAudit,
        private readonly AiBudget $budget,
    ) {}

    /**
     * Queue a new summary for a task of the current organization, or return the one
     * already being generated. A draft nobody reviewed is retired by the new request.
     *
     * $source names a system trigger ("backfill"): the requester is still recorded
     * (and billed in ai_usage), but the audit entry has no actor, since they didn't act.
     *
     * @throws AiBudgetExceeded when a new one would start and the monthly budget is used up
     */
    public function handle(Task $task, ?User $requester, ?string $source = null): TaskSummary
    {
        [$summary, $created] = DB::transaction(function () use ($task, $requester, $source) {
            // Lock the task so two clicks can't both start a summary. Scoped, so a task
            // of another organization fails here instead of being linked.
            Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            $pending = $task->summaries()->where('status', TaskSummaryStatus::Pending)->latest('id')->first();

            if ($pending !== null && $pending->created_at->gt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                return [$pending, false];
            }

            // Nothing is queued that couldn't run (the job checks again before calling).
            $this->budget->ensureAvailable();

            // Only if still pending: a late job may have just made it a draft (retired below).
            if ($pending !== null) {
                TaskSummary::query()->whereKey($pending->id)->where('status', TaskSummaryStatus::Pending)
                    ->update(['status' => TaskSummaryStatus::Failed, 'error' => 'unavailable', 'updated_at' => now()]);
            }

            $task->summaries()->where('status', TaskSummaryStatus::Draft)
                ->update(['status' => TaskSummaryStatus::Rejected, 'updated_at' => now()]);

            $summary = new TaskSummary;
            $summary->forceFill([
                'task_id' => $task->id,
                'status' => TaskSummaryStatus::Pending,
                'requested_by' => $requester?->id,
            ])->save();

            $this->recordAudit->handle(
                AuditAction::SummaryRequested,
                ['type' => 'task', 'id' => $task->id, 'label' => $task->title],
                metadata: ['summary_id' => $summary->id, ...($source !== null ? ['source' => $source] : [])],
                actor: $source === null ? $requester : null,
            );

            return [$summary, true];
        });

        if ($created) {
            GenerateTaskSummary::dispatch($summary->organization_id, $summary->id, $requester?->id);
        }

        // The sync queue (tests) may have finished it already.
        return $summary->fresh(['requester', 'reviewer']);
    }
}

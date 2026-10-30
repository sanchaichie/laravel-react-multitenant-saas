<?php

namespace App\Listeners;

use App\Actions\TaskSummaries\RequestTaskSummary;
use App\Ai\AiBudget;
use App\Enums\Permission;
use App\Events\TaskCreated;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Asks for a summary of a new task that has a description, if its creator has a
 * verified email and ai.use, and the organization's AI budget isn't used up (as
 * for POST /tasks/{task}/summary). Otherwise it skips quietly. Runs in the
 * request, after the task is saved: it only writes a row and dispatches a job, and
 * a failure here is reported, never turned into a failed task creation.
 */
class QueueTaskSummary
{
    public function __construct(
        private readonly RequestTaskSummary $requestSummary,
        private readonly CurrentOrganization $currentOrganization,
        private readonly AiBudget $budget,
    ) {}

    public function handle(TaskCreated $event): void
    {
        $organization = Organization::find($event->organizationId);

        if ($organization === null) {
            return;
        }

        $creator = User::find($event->createdBy);

        if ($creator === null || ! $creator->hasVerifiedEmail()) {
            return;
        }

        rescue(fn () => $this->currentOrganization->run($organization, function () use ($event, $creator, $organization) {
            // The creator's role in the task's organization, whatever is current.
            $role = $organization->members()->whereKey($creator->id)->first()?->membership->role;

            if ($role === null || ! $role->can(Permission::AiUse)) {
                return;
            }

            $task = Task::query()->find($event->taskId);

            if ($task === null || blank($task->description) || $this->budget->exceeded()) {
                return;
            }

            // Creating tasks doesn't pass throttle:ai, so automatic summaries get the
            // same per-organization limit. Over it the task is created without one (it
            // can still be generated from the task page).
            $key = 'ai-auto:'.$task->organization_id;
            if (RateLimiter::tooManyAttempts($key, config('ai.rate_limit_per_minute'))) {
                return;
            }
            RateLimiter::hit($key, 60);

            $this->requestSummary->handle($task, $creator);
        }));
    }
}

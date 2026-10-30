<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\TaskSummary;
use App\Models\User;
use App\Support\CurrentOrganization;

/**
 * Summaries of the current organization's tasks (found through the scope).
 * Reading follows who may view the task; reviewing, who may edit it (it costs
 * nothing). Requesting one is AI work: it also needs ai.use.
 */
class TaskSummaryPolicy
{
    public function __construct(
        private readonly TaskPolicy $tasks,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    public function view(User $user, Task $task): bool
    {
        return $this->tasks->view($user, $task);
    }

    public function create(User $user, Task $task): bool
    {
        return $this->currentOrganization->can(Permission::AiUse) && $this->tasks->update($user, $task);
    }

    public function review(User $user, TaskSummary $summary): bool
    {
        return $summary->task !== null && $this->tasks->update($user, $summary->task);
    }
}

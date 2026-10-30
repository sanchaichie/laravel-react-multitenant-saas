<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskSummary;
use App\Models\User;

/**
 * Summaries of the current organization's tasks (found through the scope).
 * Reading follows who may view the task; requesting and reviewing, who may edit it.
 * Plan 11 adds ai.use to requesting.
 */
class TaskSummaryPolicy
{
    public function __construct(private readonly TaskPolicy $tasks) {}

    public function view(User $user, Task $task): bool
    {
        return $this->tasks->view($user, $task);
    }

    public function create(User $user, Task $task): bool
    {
        return $this->tasks->update($user, $task);
    }

    public function review(User $user, TaskSummary $summary): bool
    {
        return $summary->task !== null && $this->tasks->update($user, $summary->task);
    }
}

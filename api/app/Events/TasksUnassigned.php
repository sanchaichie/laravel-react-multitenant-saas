<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * RemoveMember unassigned these tasks (a builder update: no model events). One event
 * for all of them, so listeners load what they need once. After commit.
 * Listener: QueueWebhookDeliveries (a task.updated per task, changed: assignee).
 */
class TasksUnassigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $taskIds
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $taskIds,
    ) {}
}

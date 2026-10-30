<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A task was created. Plain ids, so a listener loads what it needs inside
 * CurrentOrganization::run(). Listener: QueueTaskSummary (AI summary).
 */
class TaskCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $taskId,
        public readonly int $organizationId,
        public readonly int $createdBy,
    ) {}
}

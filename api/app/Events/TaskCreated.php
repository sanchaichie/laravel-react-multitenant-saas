<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A task was created. Plain ids, so a listener loads what it needs inside
 * CurrentOrganization::run(). After commit: a rolled-back create sends nothing.
 * Listeners: QueueTaskSummary (AI summary), QueueWebhookDeliveries.
 */
class TaskCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $taskId,
        public readonly int $organizationId,
        public readonly int $createdBy,
    ) {}
}

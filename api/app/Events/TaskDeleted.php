<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A task was deleted. It carries the task as it was (WebhookPayload::task), since
 * the row is gone by the time listeners run. After commit.
 * Listener: QueueWebhookDeliveries.
 */
class TaskDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $task
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $task,
    ) {}
}

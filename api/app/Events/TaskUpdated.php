<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A task changed (UpdateTask; RemoveMember sends TasksUnassigned). After commit.
 * Listener: QueueWebhookDeliveries.
 */
class TaskUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $changed  the payload's field names (WebhookPayload::changedFields)
     */
    public function __construct(
        public readonly int $taskId,
        public readonly int $organizationId,
        public readonly array $changed,
    ) {}
}

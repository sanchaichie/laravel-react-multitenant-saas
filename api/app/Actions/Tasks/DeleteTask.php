<?php

namespace App\Actions\Tasks;

use App\Events\TaskDeleted;
use App\Models\Task;
use App\Webhooks\WebhookPayload;
use Illuminate\Support\Facades\DB;

class DeleteTask
{
    /**
     * In a transaction, so the audit entry (Auditable) commits with the delete. The
     * event carries the task as it was (the row is gone when listeners run) and is
     * dispatched after commit.
     */
    public function handle(Task $task): void
    {
        DB::transaction(function () use ($task) {
            $snapshot = WebhookPayload::task($task);
            $task->delete();

            TaskDeleted::dispatch($task->organization_id, $snapshot);
        });
    }
}

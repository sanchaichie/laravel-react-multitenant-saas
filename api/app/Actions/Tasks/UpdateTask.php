<?php

namespace App\Actions\Tasks;

use App\Actions\Tasks\Concerns\LocksAssignee;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class UpdateTask
{
    use LocksAssignee;

    /**
     * @param  array<string, mixed>  $attributes  validated by UpdateTaskRequest (only the fields sent)
     */
    public function handle(Task $task, array $attributes): Task
    {
        return DB::transaction(function () use ($task, $attributes) {
            // Only a new assignee needs checking: keeping the current one is fine
            // (RemoveMember unassigns whoever leaves).
            if (array_key_exists('assignee_id', $attributes) && $attributes['assignee_id'] !== $task->assignee_id) {
                $this->lockAssignee($task->organization_id, $attributes['assignee_id']);
            }

            $task->update($attributes);

            return $task;
        });
    }
}

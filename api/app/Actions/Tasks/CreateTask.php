<?php

namespace App\Actions\Tasks;

use App\Actions\Tasks\Concerns\LocksAssignee;
use App\Events\TaskCreated;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class CreateTask
{
    use LocksAssignee;

    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by StoreTaskRequest
     */
    public function handle(User $creator, array $attributes): Task
    {
        $task = DB::transaction(function () use ($creator, $attributes) {
            $this->lockAssignee($this->currentOrganization->id(), $attributes['assignee_id'] ?? null);

            $task = new Task($attributes);
            $task->created_by = $creator->id;
            $task->save();

            return $task;
        });

        TaskCreated::dispatch($task->id, $task->organization_id, $creator->id);

        return $task;
    }
}

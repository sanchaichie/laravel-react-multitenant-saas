<?php

namespace App\Webhooks;

use App\Models\Task;
use Illuminate\Support\Str;

/**
 * What webhooks send. Its own shape, not TaskResource's (which depends on who's
 * asking): the task's own fields, the assignee by name, no emails.
 */
class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, type: string, created_at: string, data: array<string, mixed>}
     */
    public static function event(string $type, array $data): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'created_at' => now()->toIso8601ZuluString(),
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function task(Task $task): array
    {
        $task->loadMissing('assignee:id,name');

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->toDateString(),
            'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
            'created_by' => $task->created_by,
            'created_at' => $task->created_at?->toIso8601ZuluString(),
            'updated_at' => $task->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Changed columns as the payload names them (assignee_id → assignee).
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function changedFields(array $columns): array
    {
        $fields = array_map(fn (string $column) => $column === 'assignee_id' ? 'assignee' : $column, $columns);

        return array_values(array_diff($fields, ['updated_at', 'created_at']));
    }
}

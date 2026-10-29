<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The list's query string. Unknown values are a 422, so a typo shows instead of
 * silently listing everything. An assignee id that isn't a member matches nothing.
 */
class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'assignee' => ['sometimes', 'string', 'regex:/^(me|none|[1-9][0-9]{0,17})$/'],
            'overdue' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['newest', 'due', 'priority'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array{status?: string, priority?: string, assignee?: string, overdue?: bool, search?: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return array_filter([
            'status' => $validated['status'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'assignee' => $validated['assignee'] ?? null,
            'overdue' => $this->boolean('overdue'),
            'search' => trim((string) ($validated['search'] ?? '')),
        ]);
    }

    public function sort(): ?string
    {
        return $this->validated('sort');
    }
}

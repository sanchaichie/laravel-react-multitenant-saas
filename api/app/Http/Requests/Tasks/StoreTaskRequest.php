<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    // Who may create is TaskPolicy's call.
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => [Rule::enum(TaskStatus::class)],
            'priority' => [Rule::enum(TaskPriority::class)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            // A member of the current organization, never just any user id.
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_user', 'user_id')->where('organization_id', app(CurrentOrganization::class)->id()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.exists' => 'The assignee must be a member of this organization.',
        ];
    }
}

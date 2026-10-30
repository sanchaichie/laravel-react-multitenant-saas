<?php

namespace App\Http\Requests\Tasks;

use App\Ai\Prompts\TaskWritingPrompt;
use App\Enums\WritingHelpMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The task form's current text. Improving or shortening needs a description;
 * expanding works from the title alone.
 */
class TaskWritingHelpRequest extends FormRequest
{
    // Permission is checked in the controller (TaskPolicy::writeWithAi).
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
            'mode' => ['required', Rule::enum(WritingHelpMode::class)],
            'title' => ['nullable', 'string', 'max:255', 'required_without:description'],
            'description' => [
                'nullable', 'string', 'max:'.TaskWritingPrompt::MAX_LENGTH,
                Rule::requiredIf(fn () => $this->input('mode') !== WritingHelpMode::Expand->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required_without' => 'Enter a title or a description first.',
            'description.required' => 'Write a description first.',
        ];
    }
}

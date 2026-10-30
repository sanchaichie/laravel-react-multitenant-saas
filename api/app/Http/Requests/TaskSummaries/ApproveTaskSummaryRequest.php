<?php

namespace App\Http\Requests\TaskSummaries;

use App\Ai\Prompts\TaskSummaryPrompt;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Optional edits made before approving: the same limits as the model's output.
 */
class ApproveTaskSummaryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = TaskSummaryPrompt::rules();

        return [
            // Either may be left out; when sent, it follows the same rules.
            'summary' => ['sometimes', ...$rules['summary']],
            'key_points' => ['sometimes', ...$rules['key_points']],
            'key_points.*' => $rules['key_points.*'],
        ];
    }

    /**
     * Trimmed, so a summary of only spaces is "required" and a key point of only spaces is rejected.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('summary'))) {
            $this->merge(['summary' => trim($this->input('summary'))]);
        }

        if (is_array($this->input('key_points'))) {
            $this->merge(['key_points' => array_map(fn ($point) => is_string($point) ? trim($point) : $point, $this->input('key_points'))]);
        }
    }
}

<?php

namespace App\Http\Requests\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ListAiUsageRequest extends FormRequest
{
    // Permission is checked in the controller (AiUsagePolicy).
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
            // A UTC month, no later than this one.
            'month' => ['sometimes', 'nullable', 'date_format:Y-m', 'before_or_equal:'.CarbonImmutable::now('UTC')->format('Y-m')],
        ];
    }

    public function messages(): array
    {
        return [
            'month.date_format' => 'The month must look like 2026-10.',
            'month.before_or_equal' => 'The month can\'t be in the future.',
        ];
    }

    /**
     * The requested month in UTC, or the current one.
     */
    public function month(): CarbonImmutable
    {
        $month = $this->validated('month');

        return $month !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', 'UTC')->startOfMonth()
            : CarbonImmutable::now('UTC')->startOfMonth();
    }
}

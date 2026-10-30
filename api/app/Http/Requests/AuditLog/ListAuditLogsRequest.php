<?php

namespace App\Http\Requests\AuditLog;

use App\Enums\AuditAction;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;

/**
 * The audit log's query string. An actor id that isn't a member here matches
 * nothing (the scope keeps other organizations' entries out).
 */
class ListAuditLogsRequest extends FormRequest
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
            'action' => ['sometimes', Rule::enum(AuditAction::class)],
            'actor' => ['sometimes', 'string', 'regex:/^[1-9][0-9]{0,17}$/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Only a cursor this endpoint gave out (ordered by id): anything else is a
            // 422, not a 500 from the paginator.
            'cursor' => ['sometimes', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
                $parameters = Cursor::fromEncoded($value)?->toArray();
                if (! is_array($parameters) || ! is_int($parameters['id'] ?? null)) {
                    $fail('This link to more entries is no longer valid. Reload the page.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end date can\'t be before the start date.',
        ];
    }

    /**
     * @return array{action?: string, actor?: string, from?: string, to?: string}
     */
    public function filters(): array
    {
        return array_filter($this->safe()->only(['action', 'actor', 'from', 'to']));
    }
}

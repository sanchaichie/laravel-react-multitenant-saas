<?php

namespace App\Http\Requests\Webhooks;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

class ListWebhookDeliveriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('webhook'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a cursor this endpoint gave out (ordered by id): anything else is a 422.
            'cursor' => ['sometimes', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) {
                $parameters = Cursor::fromEncoded($value)?->toArray();
                if (! is_array($parameters) || ! is_int($parameters['id'] ?? null)) {
                    $fail('This link to more deliveries is no longer valid. Reload the page.');
                }
            }],
        ];
    }
}

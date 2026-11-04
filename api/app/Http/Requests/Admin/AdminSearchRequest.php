<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The platform admin lists: an optional search and a page. The route's
 * platform-admin middleware does the authorization.
 */
class AdminSearchRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function search(): ?string
    {
        $query = trim((string) $this->validated('q'));

        return $query === '' ? null : $query;
    }
}

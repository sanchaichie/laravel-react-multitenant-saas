<?php

namespace App\Http\Requests\Tasks;

/**
 * PATCH: the same rules, for the fields sent.
 */
class UpdateTaskRequest extends StoreTaskRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_map(fn (array $rules) => ['sometimes', ...$rules], parent::rules());
    }
}

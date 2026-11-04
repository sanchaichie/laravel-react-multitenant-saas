<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateRecoveryCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The password and a current code from the app.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}

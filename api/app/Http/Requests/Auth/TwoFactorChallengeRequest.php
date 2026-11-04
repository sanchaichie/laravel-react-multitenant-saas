<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A code from the app, or a recovery code instead.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:50'],
        ];
    }
}

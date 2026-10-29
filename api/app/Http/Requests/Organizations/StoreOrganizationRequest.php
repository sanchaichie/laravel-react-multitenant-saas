<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOrganizationRequest extends FormRequest
{
    /** How many organizations one user can own, so a script can't create endless rows. */
    public const MAX_OWNED = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Laravel's TrimStrings middleware has already trimmed the name, so "   " fails `required`.
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $owned = $this->user()->organizations()->wherePivot('role', OrganizationRole::Owner)->count();

                if ($owned >= self::MAX_OWNED) {
                    $validator->errors()->add('name', 'You can own at most '.self::MAX_OWNED.' organizations.');
                }
            },
        ];
    }
}

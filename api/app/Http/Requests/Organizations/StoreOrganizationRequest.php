<?php

namespace App\Http\Requests\Organizations;

use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
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
                // The owner roles of any organization: this counts the user's own, wherever they act now.
                $owned = $this->user()->organizations()
                    ->whereIn('organization_user.role_id', Role::withoutGlobalScope(OrganizationScope::class)->where('is_owner', true)->select('id'))
                    ->count();

                if ($owned >= self::MAX_OWNED) {
                    $validator->errors()->add('name', 'You can own at most '.self::MAX_OWNED.' organizations.');
                }
            },
        ];
    }
}

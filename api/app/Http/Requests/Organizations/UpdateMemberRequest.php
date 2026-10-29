<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    // Whether this role may be given to this member is the policy's call (MembershipPolicy::update).
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
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }

    public function role(): OrganizationRole
    {
        return OrganizationRole::from($this->validated('role'));
    }
}

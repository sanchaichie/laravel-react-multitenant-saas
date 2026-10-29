<?php

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    // Whether the role may be given is the policy's call (OrganizationInvitationPolicy::create).
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }

    public function role(): OrganizationRole
    {
        return OrganizationRole::from($this->validated('role'));
    }
}

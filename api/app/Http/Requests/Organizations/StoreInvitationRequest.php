<?php

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Organizations\Concerns\ValidatesRoleId;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvitationRequest extends FormRequest
{
    use ValidatesRoleId;

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
            'role_id' => $this->roleIdRules(),
        ];
    }
}

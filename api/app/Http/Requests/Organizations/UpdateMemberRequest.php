<?php

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Organizations\Concerns\ValidatesRoleId;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRequest extends FormRequest
{
    use ValidatesRoleId;

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
            'role_id' => $this->roleIdRules(),
        ];
    }
}

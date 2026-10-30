<?php

namespace App\Http\Requests\Organizations\Concerns;

use App\Models\Role;
use App\Support\CurrentOrganization;
use Illuminate\Validation\Rule;

/**
 * A role_id from the client is only a reference: it must be one of the current
 * organization's roles. Another organization's role id fails like an unknown one.
 */
trait ValidatesRoleId
{
    /**
     * @return list<mixed>
     */
    protected function roleIdRules(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('roles', 'id')->where('organization_id', app(CurrentOrganization::class)->id()),
        ];
    }

    public function role(): Role
    {
        return Role::query()->findOrFail($this->validated('role_id'));
    }
}

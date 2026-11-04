<?php

namespace App\Http\Requests\Admin;

/**
 * Make an existing member of the route's organization Owner. The member is looked
 * up within that organization (MakeMemberOwner), so anyone else is a 404.
 */
class MakeOwnerRequest extends AdminActionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'user_id' => ['required', 'integer', 'min:1', 'max:999999999999999999'],
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A role of the current organization, for the roles page. `permissions` is what
 * it grants (all of them for the owner role); `can` comes from the policy.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gate = Gate::forUser($request->user());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_owner' => $this->is_owner,
            'permissions' => $this->grants()->map(fn (Permission $permission) => $permission->value)->values(),
            'members_count' => $this->whenCounted('memberships'),
            'can' => [
                'update' => $gate->allows('update', $this->resource),
                'delete' => $gate->allows('delete', $this->resource),
            ],
        ];
    }
}

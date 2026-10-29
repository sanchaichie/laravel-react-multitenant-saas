<?php

namespace App\Http\Resources;

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A member of the current organization (loaded through Organization::members(),
 * so the membership is attached). `can` and `assignable_roles` come from the
 * policy, so the app never repeats the rules; it only shows or hides controls.
 *
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = $this->membership;
        $gate = Gate::forUser($request->user());

        // The roles this member may be changed to by the requesting user.
        $assignable = array_values(array_filter(
            OrganizationRole::cases(),
            fn (OrganizationRole $role) => $role !== $membership->role && $gate->allows('update', [$membership, $role]),
        ));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $membership->role->value,
            'joined_at' => $membership->created_at,
            'is_me' => $this->id === $request->user()->id,
            'assignable_roles' => array_map(fn (OrganizationRole $role) => $role->value, $assignable),
            'can' => [
                'update' => $assignable !== [],
                'delete' => $gate->allows('delete', $membership),
            ],
        ];
    }
}

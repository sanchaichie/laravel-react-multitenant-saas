<?php

namespace App\Http\Resources;

use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
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

        // The organization's roles are loaded once per request: no query per member.
        $roles = app(CurrentOrganization::class)->roles();
        $membership->setRelation('role', $roles->find($membership->role_id) ?? $membership->role);

        // The roles this member may be changed to by the requesting user.
        $assignable = $roles->filter(
            fn (Role $role) => $role->id !== $membership->role_id && $gate->allows('update', [$membership, $role]),
        )->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => new RoleSummaryResource($membership->role),
            'joined_at' => $membership->created_at,
            'is_me' => $this->id === $request->user()->id,
            'assignable_roles' => RoleSummaryResource::collection($assignable),
            'can' => [
                'update' => $assignable->isNotEmpty(),
                'delete' => $gate->allows('delete', $membership),
            ],
        ];
    }
}

<?php

namespace App\Http\Resources\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One user for a platform admin (ShowUser): their organizations and roles, and how
 * many devices are signed in.
 *
 * @mixin User
 */
class AdminUserDetailResource extends AdminUserResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'devices_count' => $this->whenCounted('tokens'),
            // An aggregate (loadMax): a raw database string, parsed to go out as ISO 8601 like other dates.
            'last_active_at' => $this->tokens_max_last_used_at === null ? null : Carbon::parse($this->tokens_max_last_used_at),
            'organizations' => $this->organizations->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'role' => $organization->membership->role->only(['id', 'name', 'is_owner']),
                'joined_at' => $organization->membership->created_at,
            ])->values(),
        ];
    }
}

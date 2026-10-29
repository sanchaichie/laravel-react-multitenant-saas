<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // The requesting user's role; present when loaded through User::organizations().
            'role' => $this->whenPivotLoadedAs('membership', 'organization_user', fn () => $this->membership->role->value),
            // What that role allows, so the app can show or hide controls (the server still decides).
            'permissions' => $this->whenPivotLoadedAs('membership', 'organization_user', fn () => array_map(
                fn (Permission $permission) => $permission->value,
                $this->membership->role->grants(),
            )),
            'created_at' => $this->created_at,
        ];
    }
}

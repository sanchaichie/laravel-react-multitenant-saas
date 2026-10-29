<?php

namespace App\Http\Resources;

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
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role where something has one (a member, an invitation, your own role): just
 * enough to show it and send its id back.
 *
 * @mixin Role
 */
class RoleSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_owner' => $this->is_owner,
        ];
    }
}

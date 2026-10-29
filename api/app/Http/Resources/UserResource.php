<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Only while still a member: a stale current_organization_id shows as null.
        $currentOrganization = $this->activeOrganization();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'current_organization' => $currentOrganization ? new OrganizationResource($currentOrganization) : null,
            'created_at' => $this->created_at,
        ];
    }
}

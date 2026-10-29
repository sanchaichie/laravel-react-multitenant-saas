<?php

namespace App\Http\Resources;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the join page shows before accepting: shown only to someone holding the
 * link and logged in as the invited address (JoinController).
 *
 * @mixin OrganizationInvitation
 */
class InvitationPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'organization' => ['name' => $this->organization->name],
            'invited_by' => $this->inviter?->name,
            'role' => $this->role->value,
            'email' => $this->email,
            'expires_at' => $this->expires_at,
            'already_member' => $request->user()->isMemberOf($this->organization_id),
        ];
    }
}

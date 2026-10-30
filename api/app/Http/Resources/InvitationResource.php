<?php

namespace App\Http\Resources;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A pending invitation of the current organization. Never includes the token
 * (only its hash is stored).
 *
 * @mixin OrganizationInvitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role ? new RoleSummaryResource($this->role) : null,
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter?->name),
            'expires_at' => $this->expires_at,
            'expired' => $this->isExpired(),
            'created_at' => $this->created_at,
            'can' => ['delete' => Gate::forUser($request->user())->allows('delete', $this->resource)],
        ];
    }
}

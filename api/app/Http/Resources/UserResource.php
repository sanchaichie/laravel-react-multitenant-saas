<?php

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
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
        $token = $this->currentAccessToken();
        $impersonation = $token instanceof PersonalAccessToken && $token->isImpersonation() ? $token : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            // A requested change, until the new address confirms it.
            'pending_email' => $this->pending_email,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            // Shows the admin area in the sidebar; the API checks it again on every admin route.
            'is_platform_admin' => $this->isPlatformAdmin(),
            // A platform admin's read-only "view as user" session: only their name, never their email.
            'impersonated_by' => $impersonation === null ? null : [
                'name' => $impersonation->impersonator?->name,
                'expires_at' => $impersonation->expires_at,
            ],
            'current_organization' => $currentOrganization ? new OrganizationResource($currentOrganization) : null,
            'created_at' => $this->created_at,
        ];
    }
}

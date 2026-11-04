<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user in the platform admin list. Every field is listed here (not reused from
 * UserResource), so nothing added elsewhere reaches the admin area by accident.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'is_platform_admin' => $this->isPlatformAdmin(),
            'suspended_at' => $this->suspended_at,
            'organizations_count' => $this->whenCounted('organizations'),
            'created_at' => $this->created_at,
        ];
    }
}

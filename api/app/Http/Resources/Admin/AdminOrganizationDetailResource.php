<?php

namespace App\Http\Resources\Admin;

use App\Actions\Admin\ShowOrganization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One organization for a platform admin, from ShowOrganization's result. Members
 * get name, email and role only. No Stripe ids, card details or task content.
 *
 * @property-read array<string, mixed> $resource
 */
class AdminOrganizationDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organization = $this->resource['organization'];

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'created_at' => $organization->created_at,
            'plan' => $this->resource['plan'],
            'subscription' => $this->resource['subscription'],
            'ai_budget' => $this->resource['ai_budget'],
            'counts' => $this->resource['counts'],
            'members' => $this->resource['members']->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'is_platform_admin' => $member->isPlatformAdmin(),
                'role' => $member->membership->role->only(['id', 'name', 'is_owner']),
                'joined_at' => $member->membership->created_at,
            ])->values(),
            'members_limit' => ShowOrganization::MEMBER_LIMIT,
        ];
    }
}

<?php

namespace App\Http\Resources\Admin;

use App\Billing\Plans;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization in the platform admin list. No Stripe ids or card details.
 *
 * @mixin Organization
 */
class AdminOrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'members_count' => $this->whenCounted('members'),
            'plan' => $this->whenLoaded('subscriptions', fn () => app(Plans::class)->for($this->resource)->key),
            'created_at' => $this->created_at,
        ];
    }
}

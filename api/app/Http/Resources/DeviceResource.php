<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One of the user's API tokens, shown as a signed-in device. Never the token itself.
 *
 * @mixin PersonalAccessToken
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()->currentAccessToken();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip_address' => $this->ip_address,
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'is_current' => $current instanceof PersonalAccessToken && $current->is($this->resource),
        ];
    }
}

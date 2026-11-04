<?php

namespace App\Http\Resources\Admin;

use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformAuditLog
 */
class PlatformAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            // Null: the console (admin:grant / admin:revoke), or a deleted admin.
            'actor' => $this->whenLoaded('actor', fn () => $this->actor?->only(['id', 'name', 'email'])),
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],
            'metadata' => $this->metadata ?? (object) [],
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}

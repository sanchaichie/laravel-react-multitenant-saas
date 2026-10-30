<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentOrganization = app(CurrentOrganization::class);
        // Email addresses (of invitees, and of members who left) only for those who may
        // see invitations: audit_log.view alone shouldn't list everyone ever invited.
        $hideEmails = ! $currentOrganization->can(Permission::MembersInvite);
        $metadata = $this->metadata ?? [];
        if ($hideEmails) {
            unset($metadata['email']);
        }

        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor?->only(['id', 'name'])),
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $hideEmails && $this->subject_type === 'invitation' ? null : $this->subject_label,
            ],
            'changes' => $this->changes ?? (object) [],
            'metadata' => $metadata ?: (object) [],
            // Personal data: only for owners, not everyone who may read the log.
            'ip_address' => $this->when($currentOrganization->role()?->is_owner === true, $this->ip_address),
            'created_at' => $this->created_at,
        ];
    }
}

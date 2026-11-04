<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Support\CurrentOrganization;
use App\Support\Impersonation;
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
        // A platform admin viewing as a member: no task content (what changed in a
        // description, say), and no IP addresses, even when that member is an owner.
        $impersonating = app(Impersonation::class)->active();
        $hideTaskContent = $impersonating && $this->subject_type === 'task';
        $metadata = $this->metadata ?? [];
        if ($hideEmails) {
            unset($metadata['email']);
        }

        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor?->only(['id', 'name'])),
            // Support did it (shown as "Platform admin"): who, and why, is only in the platform log.
            'by_platform_admin' => $this->by_platform_admin,
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $hideEmails && $this->subject_type === 'invitation' ? null : $this->subject_label,
            ],
            'changes' => ($hideTaskContent ? null : $this->changes) ?? (object) [],
            'metadata' => ($hideTaskContent ? [] : $metadata) ?: (object) [],
            // Personal data: only for owners, not everyone who may read the log.
            'ip_address' => $this->when($currentOrganization->role()?->is_owner === true && ! $impersonating, $this->ip_address),
            'created_at' => $this->created_at,
        ];
    }
}

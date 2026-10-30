<?php

namespace App\Actions\Organizations;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\DB;

class RevokeInvitation
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * Delete a pending invitation: its link stops working.
     */
    public function handle(OrganizationInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation) {
            $invitation->delete();

            $this->recordAudit->handle(
                AuditAction::InvitationRevoked,
                ['type' => 'invitation', 'id' => $invitation->id, 'label' => $invitation->email],
                metadata: ['email' => $invitation->email],
            );
        });
    }
}

<?php

namespace App\Actions\Admin;

use App\Enums\PlatformAuditAction;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EndImpersonation
{
    public function __construct(private readonly RecordPlatformAudit $recordAudit) {}

    /**
     * End a "view as user" session (Stop viewing, which logs out of it): delete its
     * token and record the end in the platform log as the admin's. An expired session
     * just ends; its start entry has the expiry.
     */
    public function handle(PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($token) {
            // Already gone (a second Stop): nothing to record.
            if (PersonalAccessToken::query()->whereKey($token->getKey())->delete() === 0) {
                return;
            }

            /** @var User $user */
            $user = $token->tokenable;

            $this->recordAudit->handle(
                PlatformAuditAction::ImpersonationEnded,
                RecordPlatformAudit::user($user),
                ['token_id' => $token->id],
                actor: $token->impersonator,
            );
        });
    }
}

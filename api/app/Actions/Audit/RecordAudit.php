<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class RecordAudit
{
    public function __construct(
        private readonly CurrentOrganization $currentOrganization,
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    /**
     * Write an audit log entry. Call it inside the change's transaction, so a rolled
     * back change leaves no entry. Never pass secrets (tokens, passwords).
     *
     * @param  array{type: string, id: int|null, label: string|null}|null  $subject
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $metadata
     * @param  Organization|null  $organization  when not acting in it as the current organization
     *                                           (joining or creating one); defaults to the current one.
     *                                           The caller must have checked the actor may act there.
     * @param  User|null  $actor  defaults to the logged-in user (none in jobs); pass it when
     *                            the user isn't logged in yet (registering)
     */
    public function handle(
        AuditAction $action,
        ?array $subject = null,
        array $changes = [],
        array $metadata = [],
        ?Organization $organization = null,
        ?User $actor = null,
    ): AuditLog {
        $user = $this->auth->guard()->user();
        $actor ??= $user instanceof User ? $user : null;

        $entry = (new AuditLog)->forceFill([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'subject_label' => isset($subject['label']) ? mb_substr($subject['label'], 0, 255) : null,
            'changes' => $changes ?: null,
            'metadata' => $metadata ?: null,
            // Only for something a logged-in user did in this request, not jobs or console.
            'ip_address' => $actor !== null ? $this->request->ip() : null,
        ]);

        // organization_id comes from the current organization (BelongsToOrganization).
        if ($organization !== null) {
            $this->currentOrganization->run($organization, fn () => $entry->save());
        } else {
            $entry->save();
        }

        return $entry;
    }
}

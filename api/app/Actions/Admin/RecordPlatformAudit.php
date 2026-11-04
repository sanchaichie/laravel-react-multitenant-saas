<?php

namespace App\Actions\Admin;

use App\Enums\PlatformAuditAction;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class RecordPlatformAudit
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    /**
     * Write a platform audit log entry, inside the change's transaction when there
     * is one. The actor is the logged-in admin (none from the console), or $actor
     * when the request is someone else's (ending a "view as user" session, which is
     * the viewed user's token). Never pass secrets (tokens, passwords) or an
     * organization's content (tasks, chats).
     *
     * @param  array{type: string, id: int|null, label: string|null}|null  $subject
     * @param  array<string, mixed>  $metadata
     */
    public function handle(PlatformAuditAction $action, ?array $subject = null, array $metadata = [], ?User $actor = null): PlatformAuditLog
    {
        $user = $this->auth->guard()->user();
        $actor ??= $user instanceof User ? $user : null;

        $entry = (new PlatformAuditLog)->forceFill([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'subject_label' => isset($subject['label']) ? mb_substr($subject['label'], 0, 255) : null,
            'metadata' => $metadata ?: null,
            // Only for something a logged-in admin did in this request, not the console.
            'ip_address' => $actor !== null ? $this->request->ip() : null,
        ]);
        $entry->save();

        return $entry;
    }

    /**
     * The subject of an entry about a user (their email: names aren't unique).
     *
     * @return array{type: string, id: int, label: string}
     */
    public static function user(User $user): array
    {
        return ['type' => 'user', 'id' => $user->id, 'label' => $user->email];
    }
}

<?php

namespace App\Actions\Webhooks;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookSigner;
use Illuminate\Support\Facades\DB;

class RollWebhookSecret
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * A new secret at once (no overlap): deliveries from now on, retries included,
     * are signed with it.
     *
     * @return string the new secret (shown once)
     */
    public function handle(WebhookEndpoint $endpoint): string
    {
        return DB::transaction(function () use ($endpoint) {
            // Locked: of two rolls at once, the second waits, so the secret it shows is the one kept.
            $endpoint = WebhookEndpoint::query()->whereKey($endpoint->id)->lock('for no key update')->firstOrFail();
            $secret = WebhookSigner::generateSecret();
            // The secret is $hidden, so Auditable records nothing: the entry below does.
            $endpoint->secret = $secret;
            $endpoint->save();

            $this->recordAudit->handle(
                AuditAction::WebhookSecretRolled,
                ['type' => 'webhook', 'id' => $endpoint->id, 'label' => $endpoint->host()],
            );

            return $secret;
        });
    }
}

<?php

namespace App\Actions\Webhooks;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

class DeleteWebhookEndpoint
{
    /**
     * Its deliveries go with it (cascading key); queued attempts then find nothing.
     * In a transaction, so the audit entry commits with the delete.
     */
    public function handle(WebhookEndpoint $endpoint): void
    {
        DB::transaction(fn () => $endpoint->delete());
    }
}

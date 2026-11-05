<?php

namespace App\Actions\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Validation\ValidationException;

class SendTestWebhook
{
    public const EVENT = 'ping';

    public function __construct(private readonly QueueDelivery $queueDelivery) {}

    public function handle(WebhookEndpoint $endpoint): WebhookDelivery
    {
        if (! $endpoint->isActive()) {
            throw ValidationException::withMessages(['endpoint' => 'Turn the webhook on first.']);
        }

        return $this->queueDelivery->toEndpoint($endpoint, self::EVENT, ['endpoint' => ['id' => $endpoint->id]]);
    }
}

<?php

namespace App\Actions\Webhooks;

use App\Enums\WebhookEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Validation\ValidationException;

class ResendWebhookDelivery
{
    public function __construct(private readonly QueueDelivery $queueDelivery) {}

    /**
     * The same event (id and payload) as a new delivery, signed afresh when sent.
     */
    public function handle(WebhookEndpoint $endpoint, WebhookDelivery $delivery): WebhookDelivery
    {
        if (! $endpoint->isActive()) {
            throw ValidationException::withMessages(['endpoint' => 'Turn the webhook on first.']);
        }

        // It would only be canceled (DeliverWebhook): say so instead. Pings always go.
        $event = WebhookEvent::tryFrom($delivery->event);
        if ($event !== null && ! $endpoint->subscribesTo($event)) {
            throw ValidationException::withMessages(['endpoint' => 'The webhook no longer subscribes to this event.']);
        }

        return $this->queueDelivery->again($delivery->setRelation('endpoint', $endpoint));
    }
}

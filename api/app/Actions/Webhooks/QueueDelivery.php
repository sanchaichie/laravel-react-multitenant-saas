<?php

namespace App\Actions\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookPayload;
use Illuminate\Support\Collection;

/**
 * Writes delivery rows and queues their first attempt. Call in the endpoints'
 * organization (CurrentOrganization), outside a transaction or after it commits.
 */
class QueueDelivery
{
    /**
     * One event to several endpoints: the same payload (and event id) for each.
     *
     * @param  Collection<int, WebhookEndpoint>  $endpoints
     * @param  array<string, mixed>  $data
     */
    public function toEndpoints(Collection $endpoints, string $type, array $data): void
    {
        $payload = WebhookPayload::event($type, $data);

        foreach ($endpoints as $endpoint) {
            $this->queue($endpoint, $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function toEndpoint(WebhookEndpoint $endpoint, string $type, array $data): WebhookDelivery
    {
        return $this->queue($endpoint, WebhookPayload::event($type, $data));
    }

    /**
     * The same event again (same id and payload), as a new delivery.
     */
    public function again(WebhookDelivery $delivery): WebhookDelivery
    {
        return $this->queue($delivery->endpoint, $delivery->payload);
    }

    /**
     * @param  array{id: string, type: string}&array<string, mixed>  $payload
     */
    private function queue(WebhookEndpoint $endpoint, array $payload): WebhookDelivery
    {
        $delivery = new WebhookDelivery;
        $delivery->webhook_endpoint_id = $endpoint->id;
        $delivery->event_id = $payload['id'];
        $delivery->event = $payload['type'];
        $delivery->payload = $payload;
        $delivery->save();

        DeliverWebhook::dispatch($delivery->organization_id, $delivery->id, 1);

        return $delivery;
    }
}

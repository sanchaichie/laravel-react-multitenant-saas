<?php

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What was sent and what came back. The payload holds task content: the route
 * isn't available in a support view (not-impersonating).
 *
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event' => $this->event,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'error' => $this->error,
            'duration_ms' => $this->duration_ms,
            'next_attempt_at' => $this->next_attempt_at,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
            'payload' => $this->payload,
        ];
    }
}

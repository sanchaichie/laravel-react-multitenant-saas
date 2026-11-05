<?php

namespace App\Http\Resources;

use App\Models\WebhookEndpoint;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never the secret (shown once, in meta.secret of the create and roll answers). A
 * URL can hold a token in its path: a platform admin's support view sees the host.
 *
 * @mixin WebhookEndpoint
 */
class WebhookEndpointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hidden = app(Impersonation::class)->active();

        return [
            'id' => $this->id,
            'url' => $hidden ? null : $this->url,
            'url_hidden' => $hidden,
            'host' => $this->host(),
            'description' => $this->description,
            'events' => $this->events,
            'enabled' => $this->isActive(),
            'disabled_reason' => $this->disabled_reason,
            'disabled_at' => $this->disabled_at,
            'last_delivery' => $this->whenLoaded('latestDelivery', fn () => $this->latestDelivery ? [
                'status' => $this->latestDelivery->status->value,
                'event' => $this->latestDelivery->event,
                'created_at' => $this->latestDelivery->created_at,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}

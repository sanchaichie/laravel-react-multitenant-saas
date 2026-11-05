<?php

namespace App\Http\Controllers\Api;

use App\Actions\Webhooks\ResendWebhookDelivery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\ListWebhookDeliveriesRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A webhook's deliveries, newest first, in cursor pages ("Load more"). {delivery}
 * must belong to {webhook} (scoped bindings).
 */
class WebhookDeliveryController extends Controller
{
    public function index(ListWebhookDeliveriesRequest $request, WebhookEndpoint $webhook): AnonymousResourceCollection
    {
        $deliveries = $webhook->deliveries()
            ->orderByDesc('id')
            ->cursorPaginate(WebhookDelivery::PER_PAGE)
            ->withQueryString();

        return WebhookDeliveryResource::collection($deliveries);
    }

    public function resend(WebhookEndpoint $webhook, WebhookDelivery $delivery, ResendWebhookDelivery $action): JsonResponse
    {
        Gate::authorize('update', $webhook);

        return (new WebhookDeliveryResource($action->handle($webhook, $delivery)->refresh()))->response()->setStatusCode(202);
    }
}

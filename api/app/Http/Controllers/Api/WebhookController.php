<?php

namespace App\Http\Controllers\Api;

use App\Actions\Webhooks\CreateWebhookEndpoint;
use App\Actions\Webhooks\DeleteWebhookEndpoint;
use App\Actions\Webhooks\RollWebhookSecret;
use App\Actions\Webhooks\SendTestWebhook;
use App\Actions\Webhooks\UpdateWebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\WebhookEndpointRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The current organization's webhooks. {webhook} binds through the organization
 * scope: another organization's is a 404.
 */
class WebhookController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);

        return WebhookEndpointResource::collection(
            WebhookEndpoint::query()->with('latestDelivery')->orderBy('id')->get(),
        )->additional(['meta' => ['max' => (int) config('webhooks.max_endpoints')]]);
    }

    public function store(WebhookEndpointRequest $request, CreateWebhookEndpoint $action): JsonResponse
    {
        [$endpoint, $secret] = $action->handle($request->endpointAttributes());

        return (new WebhookEndpointResource($endpoint->load('latestDelivery')))
            ->additional(['meta' => ['secret' => $secret]])
            ->response()->setStatusCode(201);
    }

    public function show(WebhookEndpoint $webhook): WebhookEndpointResource
    {
        Gate::authorize('view', $webhook);

        return new WebhookEndpointResource($webhook->load('latestDelivery'));
    }

    public function update(WebhookEndpointRequest $request, WebhookEndpoint $webhook, UpdateWebhookEndpoint $action): WebhookEndpointResource
    {
        return new WebhookEndpointResource($action->handle($webhook, $request->endpointAttributes())->load('latestDelivery'));
    }

    public function destroy(WebhookEndpoint $webhook, DeleteWebhookEndpoint $action): Response
    {
        Gate::authorize('delete', $webhook);

        $action->handle($webhook);

        return response()->noContent();
    }

    public function rollSecret(WebhookEndpoint $webhook, RollWebhookSecret $action): WebhookEndpointResource
    {
        Gate::authorize('update', $webhook);

        $secret = $action->handle($webhook);

        return (new WebhookEndpointResource($webhook->load('latestDelivery')))->additional(['meta' => ['secret' => $secret]]);
    }

    public function test(WebhookEndpoint $webhook, SendTestWebhook $action): JsonResponse
    {
        Gate::authorize('update', $webhook);

        return (new WebhookDeliveryResource($action->handle($webhook)->refresh()))->response()->setStatusCode(202);
    }
}

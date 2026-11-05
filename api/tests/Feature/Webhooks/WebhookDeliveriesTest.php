<?php

use App\Enums\DefaultRole;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    $this->endpoint = createWebhook($this->acme);
    Sanctum::actingAs($this->owner);
});

it('lists an endpoint\'s deliveries newest first, in cursor pages', function () {
    $deliveries = collect(range(1, 22))->map(fn () => createDelivery($this->endpoint));
    createDelivery(createWebhook($this->acme));

    $first = $this->getJson("/api/webhooks/{$this->endpoint->id}/deliveries")
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.id', $deliveries->last()->id)
        ->assertJsonPath('data.0.payload.type', 'task.created');

    $this->getJson("/api/webhooks/{$this->endpoint->id}/deliveries?cursor=".$first->json('meta.next_cursor'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.next_cursor', null);

    $this->getJson("/api/webhooks/{$this->endpoint->id}/deliveries?cursor=nonsense")->assertUnprocessable();
});

it('sends a test ping', function () {
    $this->postJson("/api/webhooks/{$this->endpoint->id}/test")
        ->assertAccepted()
        ->assertJsonPath('data.event', 'ping')
        ->assertJsonPath('data.payload.data.endpoint.id', $this->endpoint->id)
        ->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('resends a delivery as a new one with the same event', function () {
    $delivery = createDelivery($this->endpoint, ['status' => 'failed', 'attempts' => 6]);

    $response = $this->postJson("/api/webhooks/{$this->endpoint->id}/deliveries/{$delivery->id}/resend")->assertAccepted();

    expect($response->json('data.id'))->not->toBe($delivery->id)
        ->and($response->json('data.event_id'))->toBe($delivery->event_id)
        ->and($response->json('data.payload'))->toEqual($delivery->payload)
        ->and($response->json('data.attempts'))->toBe(0);
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->deliveryId === $response->json('data.id'));
});

it('refuses to send to a disabled endpoint', function () {
    $delivery = createDelivery($this->endpoint);
    $this->endpoint->forceFill(['disabled_at' => now(), 'disabled_reason' => 'manual'])->saveQuietly();

    $this->postJson("/api/webhooks/{$this->endpoint->id}/test")->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    $this->postJson("/api/webhooks/{$this->endpoint->id}/deliveries/{$delivery->id}/resend")->assertUnprocessable();
    Queue::assertNothingPushed();
});

it('only resends a delivery through its own endpoint', function () {
    $other = createWebhook($this->acme);
    $delivery = createDelivery($other);

    $this->postJson("/api/webhooks/{$this->endpoint->id}/deliveries/{$delivery->id}/resend")->assertNotFound();
});

it('shows the last delivery in the list', function () {
    createDelivery($this->endpoint, ['status' => 'failed']);
    createDelivery($this->endpoint, ['status' => 'succeeded', 'event' => 'task.updated']);

    $this->getJson('/api/webhooks')
        ->assertJsonPath('data.0.last_delivery.status', 'succeeded')
        ->assertJsonPath('data.0.last_delivery.event', 'task.updated');
});

it('limits test sends and resends', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson("/api/webhooks/{$this->endpoint->id}/test")->assertAccepted();
    }

    $this->postJson("/api/webhooks/{$this->endpoint->id}/test")->assertTooManyRequests();
    expect(inOrganization($this->acme, fn () => WebhookDelivery::query()->count()))->toBe(10);
});

it('refuses to resend an event the webhook no longer subscribes to', function () {
    $delivery = createDelivery($this->endpoint, ['event' => 'task.created']);
    $this->endpoint->forceFill(['events' => ['task.deleted']])->saveQuietly();

    $this->postJson("/api/webhooks/{$this->endpoint->id}/deliveries/{$delivery->id}/resend")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');
    Queue::assertNothingPushed();
});

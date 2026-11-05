<?php

use App\Enums\DefaultRole;
use App\Models\Task;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    [$this->betaOwner, $this->beta] = userInOrganization(DefaultRole::Owner, 'Beta');
    $this->betaEndpoint = createWebhook($this->beta, ['url' => 'https://hooks.example.com/beta']);
    $this->betaDelivery = createDelivery($this->betaEndpoint);
    $this->acmeEndpoint = createWebhook($this->acme);
    Sanctum::actingAs($this->owner);
});

it('never shows organization B\'s webhooks to A', function () {
    $this->getJson('/api/webhooks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->acmeEndpoint->id);
    $this->getJson("/api/webhooks/{$this->betaEndpoint->id}")->assertNotFound();
    $this->getJson("/api/webhooks/{$this->betaEndpoint->id}/deliveries")->assertNotFound();
});

it('never lets A change or use B\'s webhooks', function (string $method, string $uri) {
    $uri = str_replace(['{webhook}', '{delivery}'], [$this->betaEndpoint->id, $this->betaDelivery->id], $uri);

    $this->json($method, $uri, ['events' => ['task.created']])->assertNotFound();

    $endpoint = inOrganization($this->beta, fn () => WebhookEndpoint::query()->sole());
    expect($endpoint->url)->toBe('https://hooks.example.com/beta')
        ->and($endpoint->secret)->toBe('whsec_test_secret')
        ->and($endpoint->events)->toBe(['task.created', 'task.updated', 'task.deleted'])
        ->and(inOrganization($this->beta, fn () => WebhookDelivery::query()->count()))->toBe(1);
})->with([
    ['PATCH', '/api/webhooks/{webhook}'],
    ['DELETE', '/api/webhooks/{webhook}'],
    ['POST', '/api/webhooks/{webhook}/secret'],
    ['POST', '/api/webhooks/{webhook}/test'],
    ['POST', '/api/webhooks/{webhook}/deliveries/{delivery}/resend'],
]);

it('never resends B\'s delivery through A\'s endpoint', function () {
    $this->postJson("/api/webhooks/{$this->acmeEndpoint->id}/deliveries/{$this->betaDelivery->id}/resend")->assertNotFound();
});

it('sends A\'s task events only to A\'s endpoints', function () {
    $this->postJson('/api/tasks', ['title' => 'Acme only'])->assertCreated();
    $task = inOrganization($this->acme, fn () => Task::query()->sole());
    $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])->assertOk();
    $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    expect(inOrganization($this->beta, fn () => WebhookDelivery::query()->count()))->toBe(1)
        ->and(inOrganization($this->acme, fn () => WebhookDelivery::query()->pluck('webhook_endpoint_id')->unique()->all()))->toBe([$this->acmeEndpoint->id]);
});

it('creates webhooks in the current organization only, whatever the body says', function () {
    $this->postJson('/api/webhooks', [
        'url' => 'https://hooks.example.com/x',
        'events' => ['task.created'],
        'organization_id' => $this->beta->id,
    ])->assertCreated();

    expect(inOrganization($this->beta, fn () => WebhookEndpoint::query()->count()))->toBe(1)
        ->and(inOrganization($this->acme, fn () => WebhookEndpoint::query()->count()))->toBe(2);
});

it('stays isolated for someone who owns both organizations but hasn\'t switched to B', function () {
    $this->beta->members()->attach($this->owner, ['role_id' => $this->beta->role(DefaultRole::Owner)->id]);
    Sanctum::actingAs($this->owner);
    $id = $this->betaEndpoint->id;

    $this->getJson('/api/webhooks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->acmeEndpoint->id);
    $this->getJson("/api/webhooks/{$id}")->assertNotFound();
    $this->getJson("/api/webhooks/{$id}/deliveries")->assertNotFound();
    $this->patchJson("/api/webhooks/{$id}", ['events' => ['task.created']])->assertNotFound();
    $this->postJson("/api/webhooks/{$id}/secret")->assertNotFound();
    $this->postJson("/api/webhooks/{$id}/test")->assertNotFound();
    $this->postJson("/api/webhooks/{$id}/deliveries/{$this->betaDelivery->id}/resend")->assertNotFound();
    $this->deleteJson("/api/webhooks/{$id}")->assertNotFound();

    $endpoint = inOrganization($this->beta, fn () => WebhookEndpoint::query()->sole());
    expect($endpoint->events)->toBe(['task.created', 'task.updated', 'task.deleted'])
        ->and($endpoint->secret)->toBe('whsec_test_secret')
        ->and(inOrganization($this->beta, fn () => WebhookDelivery::query()->count()))->toBe(1);
});

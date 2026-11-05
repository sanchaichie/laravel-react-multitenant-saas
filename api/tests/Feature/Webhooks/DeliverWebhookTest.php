<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookSigner;
use App\Webhooks\WebhookTarget;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    $this->endpoint = createWebhook($this->acme, ['url' => 'https://hooks.example.com/acme']);
    $this->delivery = createDelivery($this->endpoint);
});

/** Run one attempt as the queue would (inside the job's organization). */
function attempt(WebhookDelivery $delivery, int $attempt = 1): WebhookDelivery
{
    $job = new DeliverWebhook($delivery->organization_id, $delivery->id, $attempt);
    inOrganization($delivery->organization, fn () => app()->call([$job, 'handle']));

    return inOrganization($delivery->organization, fn () => $delivery->fresh());
}

it('posts the signed payload and records the answer', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

    $delivery = attempt($this->delivery);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(200)
        ->and($delivery->response_body)->toBe('ok')
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull();

    Http::assertSent(function (Request $request) use ($delivery) {
        $timestamp = (int) $request->header('X-Webhook-Timestamp')[0];

        return $request->url() === 'https://hooks.example.com/acme'
            && $request->method() === 'POST'
            && $request->header('Content-Type')[0] === 'application/json'
            && $request->header('X-Webhook-Id')[0] === $delivery->event_id
            && $request->header('X-Webhook-Event')[0] === 'task.created'
            && $timestamp === now()->getTimestamp()
            && json_decode($request->body(), true) == $delivery->payload
            // What a receiver checks, with the secret it was given:
            && hash_equals('v1='.hash_hmac('sha256', "{$timestamp}.{$request->body()}", 'whsec_test_secret'), $request->header('X-Webhook-Signature')[0])
            && ! hash_equals(WebhookSigner::signature('whsec_other', $timestamp, $request->body()), $request->header('X-Webhook-Signature')[0]);
    });
    Queue::assertNothingPushed();
});

it('pins the connection to the address it checked', function () {
    expect((new WebhookTarget('https://h.example.com/', 'https', 'h.example.com', 443, '93.184.216.34'))->curlResolve())->toBe('h.example.com:443:93.184.216.34')
        ->and((new WebhookTarget('https://h.example.com/', 'https', 'h.example.com', 8443, '2606:2800::1'))->curlResolve())->toBe('h.example.com:8443:[2606:2800::1]');
});

it('retries a failed attempt later, with the next delay', function (int $attempt, int $delay) {
    Http::fake(['hooks.example.com/*' => Http::response('down', 503)]);
    $this->delivery->forceFill(['attempts' => $attempt - 1])->save();

    $delivery = attempt($this->delivery, $attempt);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe($attempt)
        ->and($delivery->response_status)->toBe(503)
        ->and($delivery->next_attempt_at->getTimestamp())->toBe(now()->addSeconds($delay)->getTimestamp());

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->attempt === $attempt + 1
        && $job->deliveryId === $delivery->id
        && $job->delay->getTimestamp() === now()->addSeconds($delay)->getTimestamp());
})->with([[1, 60], [2, 300], [3, 1800], [4, 7200], [5, 28800]]);

it('gives up after the 6th attempt and counts it against the endpoint', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 500)]);
    $this->delivery->forceFill(['attempts' => 5])->save();

    $delivery = attempt($this->delivery, 6);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($this->endpoint->fresh()->consecutive_failures)->toBe(1)
        ->and($this->endpoint->fresh()->isActive())->toBeTrue();
    Queue::assertNothingPushed();
});

it('disables the endpoint after 15 failed deliveries in a row, audited with no actor', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 500)]);
    $this->endpoint->forceFill(['consecutive_failures' => 14])->saveQuietly();
    $this->delivery->forceFill(['attempts' => 5])->save();

    attempt($this->delivery, 6);

    $endpoint = $this->endpoint->fresh();
    $entry = auditEntries($this->acme, AuditAction::WebhookDisabled)->sole();

    expect($endpoint->isActive())->toBeFalse()
        ->and($endpoint->disabled_reason)->toBe(WebhookEndpoint::DISABLED_FAILING)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->subject_label)->toBe('hooks.example.com')
        ->and(auditEntries($this->acme, AuditAction::WebhookUpdated))->toHaveCount(0);
});

it('starts the failure count over after a success', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
    $this->endpoint->forceFill(['consecutive_failures' => 7])->saveQuietly();

    attempt($this->delivery);

    expect($this->endpoint->fresh()->consecutive_failures)->toBe(0);
});

it('records timeouts and refused connections', function (string $message, string $error) {
    Http::fake(fn () => throw new ConnectionException($message));

    $delivery = attempt($this->delivery);

    expect($delivery->error)->toBe($error)->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);
})->with([
    ['cURL error 28: Operation timed out after 10001 milliseconds', 'Timed out after 10 seconds.'],
    ['cURL error 7: Failed to connect to hooks.example.com port 443', 'Couldn\'t connect.'],
]);

it('doesn\'t follow redirects', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

    $delivery = attempt($this->delivery);

    expect($delivery->response_status)->toBe(302)
        ->and($delivery->error)->toBe('Redirects aren\'t followed.')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);
    Http::assertSentCount(1);
});

it('sends nothing when the host now resolves to a private address', function () {
    fakeDns(['hooks.example.com' => ['10.0.0.8']]);
    Http::fake();

    $delivery = attempt($this->delivery);

    expect($delivery->error)->toBe('The URL must point to a public address.')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);
    Http::assertNothingSent();
});

it('cancels a delivery whose endpoint was disabled or unsubscribed meanwhile', function (array $change) {
    Http::fake();
    $this->endpoint->forceFill($change)->saveQuietly();

    expect(attempt($this->delivery)->status)->toBe(WebhookDeliveryStatus::Canceled);
    Http::assertNothingSent();
})->with([
    'disabled' => [['disabled_at' => now(), 'disabled_reason' => 'manual']],
    'unsubscribed' => [['events' => ['task.deleted']]],
]);

it('sends a test ping whatever the endpoint subscribes to', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    $this->endpoint->forceFill(['events' => ['task.deleted']])->saveQuietly();
    $ping = createDelivery($this->endpoint, ['event' => 'ping']);

    expect(attempt($ping)->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

it('does nothing when the delivery has moved past this attempt', function () {
    Http::fake();
    $this->delivery->forceFill(['attempts' => 1])->save();

    attempt($this->delivery, 1);

    Http::assertNothingSent();
});

it('keeps at most 1 KB of the answer', function () {
    Http::fake(['hooks.example.com/*' => Http::response(str_repeat('é', 2000), 200)]);

    expect(strlen(attempt($this->delivery)->response_body))->toBeLessThanOrEqual(1024)->toBeGreaterThan(1000);
});

it('counts a crashed job as a failed attempt', function () {
    $job = new DeliverWebhook($this->acme->id, $this->delivery->id, 1);
    $job->failed(new RuntimeException('boom'));

    $delivery = inOrganization($this->acme, fn () => $this->delivery->fresh());
    expect($delivery->attempts)->toBe(1)
        ->and($delivery->error)->toBe('The delivery couldn\'t be sent.')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->attempt === 2);
});

it('prunes old deliveries of every organization', function () {
    [, $beta] = userInOrganization(DefaultRole::Owner, 'Beta');
    $old = createDelivery(createWebhook($beta));
    WebhookDelivery::withoutGlobalScopes()->whereKey($old->id)->update(['created_at' => now()->subDays(31)]);

    $this->artisan('model:prune', ['--model' => [WebhookDelivery::class]])->assertSuccessful();

    expect(WebhookDelivery::withoutGlobalScopes()->pluck('id')->all())->toBe([$this->delivery->id]);
});

it('gives each retry its deadline from when it is due, not when it was queued', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 500)]);
    $this->delivery->forceFill(['attempts' => 3])->save();

    attempt($this->delivery, 4);

    Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) {
        // Attempt 5 is due in 2 hours; a crash then still gets a few minutes of retries.
        return $job->attempt === 5 && $job->retryUntil()->getTimestamp() === now()->addSeconds(7200)->addMinutes(5)->getTimestamp();
    });
});

it('leaves retries waiting on the sync queue instead of running them all at once', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 500)]);
    $job = new DeliverWebhook($this->acme->id, $this->delivery->id, 1);
    $job->setJob(Mockery::mock(Job::class, ['getConnectionName' => 'sync']));

    inOrganization($this->acme, fn () => app()->call([$job, 'handle']));
    $delivery = inOrganization($this->acme, fn () => $this->delivery->fresh());

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->next_attempt_at)->not->toBeNull();
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('counts an unexpected error after claiming the attempt as a failed one', function () {
    Http::fake(fn () => throw new RuntimeException('boom'));

    $delivery = attempt($this->delivery);

    expect($delivery->attempts)->toBe(1)
        ->and($delivery->error)->toBe('The delivery couldn\'t be sent.')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending);
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->attempt === 2);
});

it('runs on its own queue, limited per organization', function () {
    $job = new DeliverWebhook($this->acme->id, $this->delivery->id, 1);

    expect($job->queue)->toBe('webhooks')
        ->and(collect($job->middleware())->contains(fn ($m) => $m instanceof RateLimited))->toBeTrue();
});

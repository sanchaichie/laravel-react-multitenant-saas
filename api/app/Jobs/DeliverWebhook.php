<?php

namespace App\Jobs;

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\UnsafeWebhookUrl;
use App\Webhooks\WebhookSigner;
use App\Webhooks\WebhookTarget;
use App\Webhooks\WebhookUrl;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One attempt at one delivery. The delivery owns its retries, not the queue: a
 * failed attempt is recorded on the row and the next one queued with its delay
 * (config('webhooks.retry_delays')), so the page shows them and a resend is just a
 * new row. Each job knows which attempt it is and claims it on the row (a conditional
 * update), so a duplicate run does nothing; a delivery whose endpoint was disabled,
 * deleted or unsubscribed meanwhile is canceled.
 *
 * The URL is checked again before sending (WebhookUrl) and the connection pinned to
 * the address checked, with no redirects: a host that started resolving to a
 * private address since it was saved gets nothing.
 */
class DeliverWebhook extends OrganizationJob
{
    // HTTP timeout (10s) < $timeout (20s) < queue retry_after (90s).
    public int $timeout = 20;

    /**
     * @param  int|null  $dueAt  when this attempt is due (unix seconds; null: now)
     */
    public function __construct(
        int $organizationId,
        public readonly int $deliveryId,
        public readonly int $attempt,
        public readonly ?int $dueAt = null,
    ) {
        parent::__construct($organizationId);
        // Its own queue (and workers), so a slow or dead receiver never holds up mail or AI jobs.
        $this->onQueue(config('webhooks.queue'));
    }

    /**
     * Per organization: one tenant's webhooks can't keep every worker busy.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [...parent::middleware(), new RateLimited('webhooks')];
    }

    /**
     * Only for a job that crashed (not a failed send, which is handled below): a few
     * tries, then failed() counts it as a failed attempt. Counted from when the attempt
     * is due: the queue works this out when the job is pushed, without its delay, so a
     * retry hours away would otherwise be past its deadline before it ever ran.
     */
    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->dueAt ?? now()->getTimestamp())->addMinutes(5);
    }

    public function handle(WebhookUrl $webhookUrl): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || ! $this->waitingForThisAttempt($delivery)) {
            return;
        }

        $endpoint = $delivery->endpoint;
        $event = WebhookEvent::tryFrom($delivery->event);

        if ($endpoint === null || ! $endpoint->isActive() || ($event !== null && ! $endpoint->subscribesTo($event))) {
            $delivery->forceFill(['status' => WebhookDeliveryStatus::Canceled, 'next_attempt_at' => null])->save();

            return;
        }

        // Claim the attempt: of two runs of the same job (a queue redelivery), one sends.
        $claimed = WebhookDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', WebhookDeliveryStatus::Pending)
            ->where('attempts', $this->attempt - 1)
            ->update(['attempts' => $this->attempt]);

        if ($claimed !== 1) {
            return;
        }

        // From here the attempt is ours: whatever happens counts as one, so the delivery
        // never stays pending with nobody to try it again.
        try {
            $this->attemptDelivery($webhookUrl, $delivery, $endpoint);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($delivery, $endpoint, error: 'The delivery couldn\'t be sent.');
        }
    }

    /**
     * The job crashed or timed out: counts as a failed attempt, claimed or not.
     */
    public function failed(?Throwable $exception): void
    {
        $this->inOrganization(function () {
            $delivery = WebhookDelivery::query()->find($this->deliveryId);
            $endpoint = $delivery?->endpoint;

            if ($delivery !== null && $endpoint !== null
                && $delivery->status === WebhookDeliveryStatus::Pending
                && in_array($delivery->attempts, [$this->attempt - 1, $this->attempt], true)) {
                $this->recordFailure($delivery, $endpoint, error: 'The delivery couldn\'t be sent.');
            }
        });
    }

    private function waitingForThisAttempt(WebhookDelivery $delivery): bool
    {
        return $delivery->status === WebhookDeliveryStatus::Pending && $delivery->attempts === $this->attempt - 1;
    }

    private function attemptDelivery(WebhookUrl $webhookUrl, WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        $started = hrtime(true);

        try {
            $target = $webhookUrl->target($endpoint->url);
            $response = $this->send($endpoint, $delivery, $target);
        } catch (UnsafeWebhookUrl $exception) {
            $this->recordFailure($delivery, $endpoint, error: $exception->getMessage());

            return;
        } catch (ConnectionException $exception) {
            $this->recordFailure($delivery, $endpoint, error: str_contains(strtolower($exception->getMessage()), 'timed out')
                ? 'Timed out after '.config('webhooks.timeout').' seconds.'
                : 'Couldn\'t connect.', durationMs: $this->elapsedMs($started));

            return;
        }

        $durationMs = $this->elapsedMs($started);

        // Belt and braces for the pin: the address curl really used must be the checked one.
        $usedAddress = $response->handlerStats()['primary_ip'] ?? null;
        if (is_string($usedAddress) && $usedAddress !== '' && $usedAddress !== $target->address) {
            $this->recordFailure($delivery, $endpoint, error: 'The URL must point to a public address.', durationMs: $durationMs);

            return;
        }

        if ($response->successful()) {
            $this->recordSuccess($delivery, $endpoint, $response, $durationMs);

            return;
        }

        $this->recordFailure(
            $delivery,
            $endpoint,
            response: $response,
            error: $response->redirect() ? 'Redirects aren\'t followed.' : null,
            durationMs: $durationMs,
        );
    }

    private function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery, WebhookTarget $target): Response
    {
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();

        return Http::withOptions([
            'allow_redirects' => false,
            // Connect only to the address WebhookUrl just checked (no second DNS lookup),
            // and only over the URL's own protocol.
            'curl' => [CURLOPT_RESOLVE => [$target->curlResolve()]],
            // Guzzle's own option (it refuses CURLOPT_PROTOCOLS in 'curl').
            'protocols' => [$target->scheme],
            'proxy' => '',
        ])
            ->timeout(config('webhooks.timeout'))
            ->connectTimeout(config('webhooks.connect_timeout'))
            ->withUserAgent('react-saas-webhooks/1.0')
            ->withHeaders([
                'X-Webhook-Id' => $delivery->event_id,
                'X-Webhook-Event' => $delivery->event,
                'X-Webhook-Timestamp' => (string) $timestamp,
                'X-Webhook-Signature' => WebhookSigner::signature($endpoint->secret, $timestamp, $body),
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint->url);
    }

    private function recordSuccess(WebhookDelivery $delivery, WebhookEndpoint $endpoint, Response $response, int $durationMs): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Succeeded,
            'attempts' => $this->attempt,
            'response_status' => $response->status(),
            'response_body' => $this->bodyOf($response),
            'error' => null,
            'duration_ms' => $durationMs,
            'next_attempt_at' => null,
            'delivered_at' => now(),
        ])->save();

        // A builder update: not a change anyone made (Auditable skips it). Decided by the
        // row, not the endpoint loaded earlier: a failure counted meanwhile is reset too.
        WebhookEndpoint::query()->whereKey($endpoint->id)->where('consecutive_failures', '>', 0)->update(['consecutive_failures' => 0]);
    }

    private function recordFailure(
        WebhookDelivery $delivery,
        WebhookEndpoint $endpoint,
        ?Response $response = null,
        ?string $error = null,
        ?int $durationMs = null,
    ): void {
        $delays = config('webhooks.retry_delays');
        $last = $this->attempt >= (int) config('webhooks.max_attempts');
        $nextAt = $last ? null : now()->addSeconds($delays[$this->attempt - 1] ?? end($delays));

        $delivery->forceFill([
            'status' => $last ? WebhookDeliveryStatus::Failed : WebhookDeliveryStatus::Pending,
            'attempts' => $this->attempt,
            'response_status' => $response?->status(),
            'response_body' => $response ? $this->bodyOf($response) : null,
            'error' => $error,
            'duration_ms' => $durationMs,
            'next_attempt_at' => $nextAt,
        ])->save();

        if ($nextAt !== null) {
            // The sync queue ignores delays: every retry would run at once, inside the
            // request. There the delivery waits (pending, with its next time) for a worker
            // that never comes: retries need a real queue (`php artisan queue:work`).
            if ($this->job?->getConnectionName() !== 'sync') {
                self::dispatch($this->organizationId, $delivery->id, $this->attempt + 1, $nextAt->getTimestamp())->delay($nextAt);
            }

            return;
        }

        $this->countFailedDelivery($endpoint->id);
    }

    /**
     * Another delivery that used up its attempts. On the locked endpoint row, so
     * deliveries failing at once count each other; the threshold disables it.
     */
    private function countFailedDelivery(int $endpointId): void
    {
        DB::transaction(function () use ($endpointId) {
            // FOR NO KEY UPDATE: new deliveries (whose foreign key points here) aren't held up.
            $endpoint = WebhookEndpoint::query()->whereKey($endpointId)->lock('for no key update')->first();

            if ($endpoint === null) {
                return;
            }

            $failures = $endpoint->consecutive_failures + 1;
            $disable = $endpoint->isActive() && $failures >= (int) config('webhooks.disable_after_failures');

            WebhookEndpoint::query()->whereKey($endpointId)->update([
                'consecutive_failures' => $failures,
                ...($disable ? ['disabled_at' => now(), 'disabled_reason' => WebhookEndpoint::DISABLED_FAILING] : []),
            ]);

            if ($disable) {
                app(RecordAudit::class)->handle(
                    AuditAction::WebhookDisabled,
                    ['type' => 'webhook', 'id' => $endpoint->id, 'label' => $endpoint->host()],
                    metadata: ['failed_deliveries' => $failures],
                    withoutActor: true,
                );
            }
        });
    }

    private function bodyOf(Response $response): ?string
    {
        $body = mb_strcut($response->body(), 0, (int) config('webhooks.response_body_limit'), 'UTF-8');

        return $body === '' ? null : mb_scrub($body, 'UTF-8');
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}

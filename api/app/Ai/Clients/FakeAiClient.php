<?php

namespace App\Ai\Clients;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * No network. Returns pushed responses (or throws pushed exceptions) in order;
 * when none are left, returns a generic valid answer for the request's feature.
 * Used by tests and by AI_PROVIDER=fake for local and e2e runs.
 */
class FakeAiClient implements AiClient
{
    /** @var list<AiResponse|array<string, mixed>|Throwable> */
    private array $queue = [];

    /** @var list<AiRequest> */
    private array $sent = [];

    public function __construct(private readonly string $model = 'fake-model') {}

    /**
     * Queue the next result: an AiResponse, decoded JSON output, or an exception to throw.
     *
     * @param  AiResponse|array<string, mixed>|Throwable  $result
     */
    public function push(AiResponse|array|Throwable $result): static
    {
        $this->queue[] = $result;

        return $this;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->sent[] = $request;

        $next = array_shift($this->queue) ?? $this->defaultOutput($request);

        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next instanceof AiResponse) {
            return $next;
        }

        return new AiResponse(
            text: json_encode($next, JSON_THROW_ON_ERROR),
            json: $next,
            model: $this->model,
            inputTokens: 100,
            outputTokens: 50,
            stopReason: 'end_turn',
        );
    }

    /**
     * @return list<AiRequest>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * @param  (Closure(AiRequest): bool)|null  $callback
     */
    public function assertSent(?Closure $callback = null): void
    {
        $matching = $callback === null ? $this->sent : array_filter($this->sent, $callback);

        Assert::assertNotEmpty($matching, 'No matching AI request was sent.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, count($this->sent).' AI request(s) were sent unexpectedly.');
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOutput(AiRequest $request): array
    {
        return match ($request->feature) {
            'task_summary' => [
                'summary' => 'A short summary of the task, written by the fake AI client.',
                'key_points' => ['First key point', 'Second key point'],
            ],
            default => ['text' => 'Fake response'],
        };
    }
}

<?php

namespace App\Ai\Clients;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * No network. Returns pushed responses and streams (or throws pushed exceptions) in
 * order; when none are left, returns a generic valid answer for the request's feature.
 * Used by tests and by AI_PROVIDER=fake for local and e2e runs.
 */
class FakeAiClient implements AiClient
{
    /** In a pushed stream: stop here, as if the provider connection were cut by the caller. */
    public const ABORT = "\0abort";

    /** @var list<AiResponse|array<string, mixed>|Throwable> */
    private array $queue = [];

    /** @var list<list<string|Throwable>|Throwable> */
    private array $streams = [];

    /** @var list<AiRequest> */
    private array $sent = [];

    /**
     * @param  int  $streamDelayMs  Pause between streamed pieces (AI_FAKE_STREAM_DELAY_MS), so
     *                              e2e runs show the text arriving. 0 in tests.
     */
    public function __construct(
        private readonly string $model = 'fake-model',
        private readonly int $streamDelayMs = 0,
    ) {}

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

    /**
     * Queue the next stream: its text pieces in order (an exception among them is thrown
     * at that point, after the pieces before it; ABORT stops there), or an exception
     * thrown before any text.
     *
     * @param  list<string|Throwable>|Throwable  $chunks
     */
    public function pushStream(array|Throwable $chunks): static
    {
        $this->streams[] = $chunks;

        return $this;
    }

    public function stream(AiRequest $request, Closure $onText): AiResponse
    {
        $this->sent[] = $request;

        $chunks = array_shift($this->streams) ?? $this->defaultStream($request);

        if ($chunks instanceof Throwable) {
            throw $chunks;
        }

        $text = '';
        $sent = 0;

        foreach ($chunks as $chunk) {
            if ($chunk instanceof Throwable) {
                throw $chunk;
            }

            if ($chunk === self::ABORT) {
                return new AiResponse($text, null, $this->model, 100, $sent, 'aborted');
            }

            if ($sent > 0 && $this->streamDelayMs > 0) {
                usleep($this->streamDelayMs * 1000);
            }

            $text .= $chunk;
            $sent++;

            if ($onText($chunk) === false) {
                return new AiResponse($text, null, $this->model, 100, $sent, 'aborted');
            }
        }

        return new AiResponse($text, null, $this->model, 100, $sent, 'end_turn');
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
     * @return list<string>
     */
    private function defaultStream(AiRequest $request): array
    {
        $text = match ($request->feature) {
            'task_writing' => 'A clearer description of the task, written by the fake AI client. It keeps the meaning of '
                .'the draft, fixes the wording, and lists what needs to happen next so the team knows where to start.',
            'task_chat' => 'Here is what I can tell from the task: the description lists the steps that are left, '
                .'so start with the first one and check the due date. This answer comes from the fake AI client.',
            default => 'Fake streamed response',
        };

        // Word by word, as a provider would send it.
        return preg_split('/(?<= )/', $text) ?: [$text];
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

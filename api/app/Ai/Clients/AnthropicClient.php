<?php

namespace App\Ai\Clients;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use Closure;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Claude Messages API (POST /v1/messages) over Laravel's HTTP client.
 */
class AnthropicClient implements AiClient
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    // Seconds one read of a streamed answer may wait for the next bytes.
    private const STREAM_READ_TIMEOUT = 30;

    /**
     * @param  array{api_key: string|null, base_url: string, version: string}  $provider
     * @param  list<string>  $fallbackModels
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $provider,
        private readonly string $model,
        private readonly int $timeout,
        private readonly int $maxTokens,
        private readonly ?string $fallbacks = null,
        private readonly array $fallbackModels = [],
        private readonly int $streamTimeout = 120,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        [$headers, $body] = $this->payload($request);

        try {
            $response = $this->http
                ->baseUrl($this->provider['base_url'])
                ->withHeaders($headers)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->post('/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('Could not reach the AI provider: '.$e->getMessage(), 'connection');
        }

        if ($response->failed()) {
            $this->throwForStatus($response);
        }

        return $this->toResponse($request, (array) $response->json());
    }

    public function stream(AiRequest $request, Closure $onText): AiResponse
    {
        if ($request->schema !== null) {
            throw new AiRequestFailed('Structured output can\'t be streamed.', 'unsupported');
        }

        [$headers, $body] = $this->payload($request);
        $body['stream'] = true;

        try {
            $response = $this->http
                ->baseUrl($this->provider['base_url'])
                ->withHeaders($headers)
                ->accept('text/event-stream')
                // With stream => true the timeout limits each read, not the whole answer:
                // events() enforces the total deadline.
                ->withOptions(['stream' => true, 'read_timeout' => self::STREAM_READ_TIMEOUT])
                ->connectTimeout(10)
                ->timeout($this->streamTimeout)
                ->post('/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('Could not reach the AI provider: '.$e->getMessage(), 'connection');
        }

        if ($response->failed()) {
            $this->throwForStatus($response);
        }

        $state = ['text' => '', 'model' => $this->model, 'input' => 0, 'output' => 0, 'stop' => null];
        // By reference: what has arrived when the stream stops.
        $partial = function () use (&$state) {
            return $this->partialResponse($state);
        };
        $source = $response->toPsrResponse()->getBody();
        $deadline = microtime(true) + $this->streamTimeout;

        foreach ($this->events($source, $partial, $deadline) as $event) {
            $type = $event['type'] ?? null;

            if ($type === 'message_start') {
                $state['model'] = (string) ($event['message']['model'] ?? $this->model);
                $state['input'] = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                $state['output'] = (int) ($event['message']['usage']['output_tokens'] ?? 0);
            } elseif ($type === 'content_block_delta' && ($event['delta']['type'] ?? null) === 'text_delta') {
                $text = (string) ($event['delta']['text'] ?? '');
                $state['text'] .= $text;

                if ($text !== '' && $onText($text) === false) {
                    $source->close();
                    $state['stop'] = 'aborted';

                    return $partial();
                }
            } elseif ($type === 'message_delta') {
                $state['stop'] = $event['delta']['stop_reason'] ?? $state['stop'];
                $state['input'] = (int) ($event['usage']['input_tokens'] ?? $state['input']);
                $state['output'] = (int) ($event['usage']['output_tokens'] ?? $state['output']);
            } elseif ($type === 'error') {
                $errorType = (string) ($event['error']['type'] ?? 'unknown_error');
                $message = "AI provider stream error: {$errorType}";

                // Overloaded and server errors may work later; anything else won't.
                if (in_array($errorType, ['overloaded_error', 'api_error', 'rate_limit_error'], true)) {
                    throw new AiUnavailable($message, $errorType, $partial());
                }

                throw new AiRequestFailed($message, $errorType, $partial());
            }
        }

        if ($state['stop'] === null) {
            throw new AiUnavailable('The AI provider stream ended early.', 'stream_incomplete', $partial());
        }

        return $this->checkStopReason(new AiResponse(
            text: $state['text'],
            json: null,
            model: $state['model'],
            inputTokens: $state['input'],
            outputTokens: $state['output'],
            stopReason: (string) $state['stop'],
        ));
    }

    /**
     * The decoded data of each server-sent event in the body, as it arrives, until
     * the body ends. The whole answer must arrive before $deadline, and no single
     * read may wait longer than STREAM_READ_TIMEOUT (a stalled provider).
     *
     * @param  Closure(): AiResponse  $partial
     * @return Generator<int, array<string, mixed>>
     */
    private function events(StreamInterface $source, Closure $partial, float $deadline): Generator
    {
        $buffer = '';

        while (true) {
            if (microtime(true) > $deadline) {
                $source->close();

                throw new AiUnavailable('The AI provider stream took too long.', 'timeout', $partial());
            }

            try {
                $chunk = $source->eof() ? '' : $source->read(8192);
            } catch (RuntimeException $e) {
                throw new AiUnavailable('The AI provider stream broke off: '.$e->getMessage(), 'connection', $partial());
            }

            if ($chunk === '' && $source->eof()) {
                break;
            }

            // Nothing arrived within the read timeout: the provider stalled.
            if ($chunk === '' && $source->getMetadata('timed_out') === true) {
                $source->close();

                throw new AiUnavailable('The AI provider stream stalled.', 'timeout', $partial());
            }

            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($end = strpos($buffer, "\n\n")) !== false) {
                $block = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 2);

                $data = collect(explode("\n", $block))
                    ->filter(fn (string $line) => str_starts_with($line, 'data:'))
                    ->map(fn (string $line) => ltrim(substr($line, 5)))
                    ->implode("\n");

                $event = json_decode($data, true);

                if (is_array($event)) {
                    yield $event;
                }
            }
        }
    }

    /**
     * What arrived before a stream stopped. The provider only reports output tokens at
     * the end, so they're estimated from the text (about 4 characters per token) to keep
     * stopped streams from counting as nearly free against the budget.
     *
     * @param  array{text: string, model: string, input: int, output: int, stop: string|null}  $state
     */
    private function partialResponse(array $state): AiResponse
    {
        return new AiResponse(
            text: $state['text'],
            json: null,
            model: $state['model'],
            inputTokens: $state['input'],
            outputTokens: max($state['output'], (int) ceil(strlen($state['text']) / 4)),
            stopReason: (string) ($state['stop'] ?? 'incomplete'),
        );
    }

    /**
     * Headers and body for POST /v1/messages.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function payload(AiRequest $request): array
    {
        if (blank($this->provider['api_key'])) {
            throw new AiRequestFailed('ANTHROPIC_API_KEY is not set.', 'missing_api_key');
        }

        $headers = [
            'x-api-key' => $this->provider['api_key'],
            'anthropic-version' => $this->provider['version'],
        ];

        $body = [
            'model' => $this->model,
            'max_tokens' => $request->maxTokens ?? $this->maxTokens,
            'system' => $request->system,
            'messages' => $request->messages,
        ];

        if ($request->schema !== null) {
            $body['output_config']['format'] = ['type' => 'json_schema', 'schema' => $request->schema];
        }

        if ($request->effort !== null) {
            $body['output_config']['effort'] = $request->effort;
        }

        if ($this->fallbacks !== null && in_array($this->model, $this->fallbackModels, true)) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
            $body['fallbacks'] = $this->fallbacks;
        }

        return [$headers, $body];
    }

    private function throwForStatus(Response $response): never
    {
        $status = $response->status();
        $message = "AI provider returned {$status}: ".($response->json('error.type') ?? 'unknown error');

        // 429 rate limited, 5xx and 529 overloaded: try again later.
        if ($status === 429 || $status >= 500) {
            throw new AiUnavailable($message, "http_{$status}");
        }

        throw new AiRequestFailed($message, "http_{$status}");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toResponse(AiRequest $request, array $data): AiResponse
    {
        // Only text blocks: thinking blocks (on by default on current models) are skipped.
        $text = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        $response = new AiResponse(
            text: $text,
            json: null,
            model: (string) ($data['model'] ?? $this->model),
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            stopReason: (string) ($data['stop_reason'] ?? ''),
        );

        // Why the model stopped, before trusting the content.
        $this->checkStopReason($response);

        if ($request->schema === null) {
            return $response;
        }

        $json = json_decode($text, true);

        if (! is_array($json)) {
            throw new AiRequestFailed('The response was not valid JSON.', 'invalid_json', $response);
        }

        return new AiResponse(
            text: $text,
            json: $json,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            stopReason: $response->stopReason,
        );
    }

    private function checkStopReason(AiResponse $response): AiResponse
    {
        if ($response->stopReason === 'refusal') {
            throw new AiRequestFailed('The model declined the request.', 'refusal', $response);
        }

        if ($response->stopReason === 'max_tokens') {
            throw new AiRequestFailed('The response was cut off at max_tokens.', 'max_tokens', $response);
        }

        return $response;
    }
}

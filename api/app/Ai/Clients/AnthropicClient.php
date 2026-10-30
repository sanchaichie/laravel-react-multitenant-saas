<?php

namespace App\Ai\Clients;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Claude Messages API (POST /v1/messages) over Laravel's HTTP client.
 */
class AnthropicClient implements AiClient
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

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
        if ($response->stopReason === 'refusal') {
            throw new AiRequestFailed('The model declined the request.', 'refusal', $response);
        }

        if ($response->stopReason === 'max_tokens') {
            throw new AiRequestFailed('The response was cut off at max_tokens.', 'max_tokens', $response);
        }

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
}

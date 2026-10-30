<?php

use App\Ai\AiRequest;
use App\Ai\Clients\AnthropicClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function anthropicClient(string $model = 'claude-haiku-5-5', ?string $fallbacks = null, ?string $apiKey = 'test-key'): AnthropicClient
{
    return new AnthropicClient(
        app(HttpFactory::class),
        ['api_key' => $apiKey, 'base_url' => 'https://api.anthropic.com', 'version' => '2023-06-01'],
        $model,
        60,
        4000,
        $fallbacks,
        ['claude-opus-5-5', 'claude-sonnet-5-5'],
    );
}

function summaryRequest(): AiRequest
{
    return new AiRequest(
        feature: 'task_summary',
        system: 'Summarize.',
        messages: [['role' => 'user', 'content' => '<task>X</task>']],
        schema: ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary'], 'additionalProperties' => false],
        maxTokens: 2000,
        effort: 'low',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function messageResponse(array $overrides = []): array
{
    return array_merge([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => ''],
            ['type' => 'text', 'text' => '{"summary":"Short."}'],
        ],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
    ], $overrides);
}

it('sends a structured-output request and parses text, json and usage', function () {
    Http::fake(['api.anthropic.com/v1/messages' => Http::response(messageResponse())]);

    $response = anthropicClient()->generate(summaryRequest());

    expect($response->json)->toBe(['summary' => 'Short.'])
        ->and($response->text)->toBe('{"summary":"Short."}')
        ->and($response->model)->toBe('claude-haiku-5-5')
        ->and($response->inputTokens)->toBe(120)
        ->and($response->outputTokens)->toBe(30);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && ! $request->hasHeader('anthropic-beta')
            && $request['model'] === 'claude-haiku-5-5'
            && $request['max_tokens'] === 2000
            && $request['system'] === 'Summarize.'
            && $request['output_config']['format']['type'] === 'json_schema'
            && $request['output_config']['effort'] === 'low'
            && ! isset($request['fallbacks']);
    });
});

it('sends the refusal fallback only when configured and the model supports it', function () {
    Http::fake(['*' => Http::response(messageResponse(['model' => 'claude-opus-5']))]);

    // Haiku doesn't support it: not sent even when configured.
    anthropicClient('claude-haiku-5-5', 'default')->generate(summaryRequest());
    Http::assertSent(fn (Request $request) => $request['model'] === 'claude-haiku-5-5' && ! isset($request['fallbacks']));

    $response = anthropicClient('claude-opus-5-5', 'default')->generate(summaryRequest());
    Http::assertSent(fn (Request $request) => $request['model'] === 'claude-opus-5-5'
        && $request['fallbacks'] === 'default'
        && $request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01'));

    // The model that actually answered is reported.
    expect($response->model)->toBe('claude-opus-5');
});

it('treats rate limits, server errors and connection failures as retryable', function (mixed $fake, string $code) {
    Http::fake(['*' => $fake]);

    expect(fn () => anthropicClient()->generate(summaryRequest()))
        ->toThrow(fn (AiUnavailable $e) => expect($e->errorCode)->toBe($code));
})->with([
    '429' => [fn () => Http::response(['error' => ['type' => 'rate_limit_error']], 429), 'http_429'],
    '500' => [fn () => Http::response(['error' => ['type' => 'api_error']], 500), 'http_500'],
    '529' => [fn () => Http::response(['error' => ['type' => 'overloaded_error']], 529), 'http_529'],
    'connection' => [fn () => Http::failedConnection('timed out'), 'connection'],
]);

it('fails without retry on bad requests, refusals, cut-offs and invalid json', function (array $body, int $status, string $code, bool $hasResponse) {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => anthropicClient()->generate(summaryRequest()))
        ->toThrow(function (AiRequestFailed $e) use ($code, $hasResponse) {
            expect($e->errorCode)->toBe($code)
                ->and($e->response !== null)->toBe($hasResponse);
        });
})->with([
    'bad request' => [['error' => ['type' => 'invalid_request_error']], 400, 'http_400', false],
    'refusal' => [messageResponse(['stop_reason' => 'refusal', 'content' => []]), 200, 'refusal', true],
    'max tokens' => [messageResponse(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"summ']]]), 200, 'max_tokens', true],
    'invalid json' => [messageResponse(['content' => [['type' => 'text', 'text' => 'Sure! Here it is']]]), 200, 'invalid_json', true],
]);

it('refuses to call the API without a key', function () {
    Http::fake();

    expect(fn () => anthropicClient(apiKey: null)->generate(summaryRequest()))
        ->toThrow(fn (AiRequestFailed $e) => expect($e->errorCode)->toBe('missing_api_key'));

    Http::assertNothingSent();
});

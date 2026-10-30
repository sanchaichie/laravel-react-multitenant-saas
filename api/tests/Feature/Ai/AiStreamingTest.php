<?php

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\AiStreamSlots;
use App\Ai\Clients\AnthropicClient;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use App\Exceptions\NoCurrentOrganization;
use App\Http\Responses\AiEventStream;
use App\Models\AiUsage;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function streamingClient(): AnthropicClient
{
    return new AnthropicClient(
        app(HttpFactory::class),
        ['api_key' => 'test-key', 'base_url' => 'https://api.anthropic.com', 'version' => '2023-06-01'],
        'claude-haiku-5-5',
        60,
        4000,
        streamTimeout: 120,
    );
}

function textRequest(): AiRequest
{
    return new AiRequest(feature: 'test_stream', system: 'Rewrite.', messages: [['role' => 'user', 'content' => 'Hi']], maxTokens: 500);
}

/**
 * A Messages API event stream: message_start, one text block with these deltas,
 * then message_delta (unless $stopReason is null) and message_stop.
 *
 * @param  list<string>  $texts
 * @param  list<array<string, mixed>>  $extra  Events inserted after the deltas.
 */
function sseBody(array $texts, ?string $stopReason = 'end_turn', array $extra = [], string $eol = "\n"): string
{
    $events = [
        ['type' => 'message_start', 'message' => ['model' => 'claude-haiku-5-5', 'usage' => ['input_tokens' => 50, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'ping'],
        ...array_map(fn (string $text) => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]], $texts),
        ...$extra,
    ];

    if ($stopReason !== null) {
        $events[] = ['type' => 'content_block_stop', 'index' => 0];
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason], 'usage' => ['output_tokens' => 42]];
        $events[] = ['type' => 'message_stop'];
    }

    return collect($events)
        ->map(fn (array $event) => "event: {$event['type']}{$eol}data: ".json_encode($event).$eol.$eol)
        ->implode('');
}

function fakeStream(string $body, int $status = 200): void
{
    Http::fake(['api.anthropic.com/v1/messages' => Http::response($body, $status, ['Content-Type' => 'text/event-stream'])]);
}

it('streams text deltas in order and returns the full answer with usage', function () {
    fakeStream(sseBody(['Hello', ' there', '!']));
    $pieces = [];

    $response = streamingClient()->stream(textRequest(), function (string $text) use (&$pieces) {
        $pieces[] = $text;
    });

    expect($pieces)->toBe(['Hello', ' there', '!'])
        ->and($response->text)->toBe('Hello there!')
        ->and($response->model)->toBe('claude-haiku-5-5')
        ->and($response->inputTokens)->toBe(50)
        ->and($response->outputTokens)->toBe(42)
        ->and($response->stopReason)->toBe('end_turn');

    Http::assertSent(fn (Request $request) => $request['stream'] === true
        && $request['max_tokens'] === 500
        && $request->hasHeader('x-api-key', 'test-key'));
});

it('handles CRLF line endings', function () {
    fakeStream(sseBody(['A', 'B'], eol: "\r\n"));

    expect(streamingClient()->stream(textRequest(), fn () => null)->text)->toBe('AB');
});

it('stops reading when the callback returns false, estimating the output tokens so far', function () {
    fakeStream(sseBody(['First chunk of text', 'never read']));
    $pieces = [];

    $response = streamingClient()->stream(textRequest(), function (string $text) use (&$pieces) {
        $pieces[] = $text;

        return false;
    });

    expect($pieces)->toBe(['First chunk of text'])
        ->and($response->stopReason)->toBe('aborted')
        ->and($response->text)->toBe('First chunk of text')
        ->and($response->inputTokens)->toBe(50)
        // 19 characters at about 4 per token, more than the 1 reported at the start.
        ->and($response->outputTokens)->toBe(5);
});

it('throws a retryable error with the partial answer on an overloaded error part-way', function () {
    fakeStream(sseBody(['Partial'], null, [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]));
    $pieces = [];

    expect(function () use (&$pieces) {
        streamingClient()->stream(textRequest(), function (string $text) use (&$pieces) {
            $pieces[] = $text;
        });
    })->toThrow(function (AiUnavailable $e) {
        expect($e->errorCode)->toBe('overloaded_error')
            ->and($e->response?->text)->toBe('Partial')
            ->and($e->response?->inputTokens)->toBe(50);
    });

    expect($pieces)->toBe(['Partial']);
});

it('fails without retry on other error events', function () {
    fakeStream(sseBody([], null, [['type' => 'error', 'error' => ['type' => 'invalid_request_error']]]));

    expect(fn () => streamingClient()->stream(textRequest(), fn () => null))
        ->toThrow(fn (AiRequestFailed $e) => expect($e->errorCode)->toBe('invalid_request_error'));
});

it('treats a stream that ends without a stop reason as broken off', function () {
    fakeStream(sseBody(['Half an ans'], null));

    expect(fn () => streamingClient()->stream(textRequest(), fn () => null))
        ->toThrow(function (AiUnavailable $e) {
            expect($e->errorCode)->toBe('stream_incomplete')
                ->and($e->response?->text)->toBe('Half an ans');
        });
});

it('fails on refusals and cut-offs at the end of the stream, keeping the answer for usage', function (string $stopReason) {
    fakeStream(sseBody(['Some text'], $stopReason));

    expect(fn () => streamingClient()->stream(textRequest(), fn () => null))
        ->toThrow(function (AiRequestFailed $e) use ($stopReason) {
            expect($e->errorCode)->toBe($stopReason)
                ->and($e->response?->outputTokens)->toBe(42);
        });
})->with(['refusal', 'max_tokens']);

it('maps HTTP errors before the stream starts like normal calls', function () {
    fakeStream(json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error']]), 529);

    expect(fn () => streamingClient()->stream(textRequest(), fn () => null))
        ->toThrow(fn (AiUnavailable $e) => expect($e->errorCode)->toBe('http_529'));
});

it('refuses to stream structured output', function () {
    Http::fake();
    $request = new AiRequest(feature: 'x', system: 'S', messages: [], schema: ['type' => 'object']);

    expect(fn () => streamingClient()->stream($request, fn () => null))
        ->toThrow(fn (AiRequestFailed $e) => expect($e->errorCode)->toBe('unsupported'));

    Http::assertNothingSent();
});

it('records a streamed call in ai_usage for the current organization', function () {
    [$user, $acme] = userInOrganization();
    $this->ai->pushStream(['One ', 'two']);

    $response = inOrganization($acme, fn () => app(AiClient::class)->stream(
        new AiRequest(feature: 'test_stream', system: 'S', messages: [], userId: $user->id),
        fn () => null,
    ));

    expect($response->text)->toBe('One two')
        ->and(AiUsage::withoutGlobalScopes()->sole())
        ->organization_id->toBe($acme->id)
        ->user_id->toBe($user->id)
        ->feature->toBe('test_stream')
        ->status->toBe('succeeded');
});

it('records a stream that broke off part-way with the tokens so far', function () {
    [, $acme] = userInOrganization();
    $partial = new AiResponse('Par', null, 'claude-haiku-5-5', 300, 20, 'incomplete');
    $this->ai->pushStream(['Par', new AiUnavailable('broke off', 'stream_incomplete', $partial)]);

    inOrganization($acme, fn () => expect(fn () => app(AiClient::class)->stream(textRequest(), fn () => null))
        ->toThrow(AiUnavailable::class));

    expect(AiUsage::withoutGlobalScopes()->sole())
        ->status->toBe('failed')
        ->error_code->toBe('stream_incomplete')
        ->input_tokens->toBe(300)
        ->output_tokens->toBe(20);
});

it('records a stream the caller stopped', function () {
    [, $acme] = userInOrganization();
    $this->ai->pushStream(['a', 'b', 'c']);

    $response = inOrganization($acme, fn () => app(AiClient::class)->stream(textRequest(), fn () => false));

    expect($response->stopReason)->toBe('aborted')
        ->and($response->text)->toBe('a')
        ->and(AiUsage::withoutGlobalScopes()->sole())
        ->status->toBe('succeeded')
        ->error_code->toBe('aborted')
        ->output_tokens->toBe(1);
});

it('refuses to stream once the budget is used up, before anything is sent or recorded', function () {
    config(['ai.monthly_budget_usd' => 1]);
    [, $acme] = userInOrganization();
    recordAiUsage($acme, 1.0);

    inOrganization($acme, fn () => expect(fn () => app(AiClient::class)->stream(textRequest(), fn () => null))
        ->toThrow(AiBudgetExceeded::class));

    $this->ai->assertNothingSent();
    expect(AiUsage::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses to stream outside any organization', function () {
    expect(fn () => app(AiClient::class)->stream(textRequest(), fn () => null))
        ->toThrow(NoCurrentOrganization::class);

    $this->ai->assertNothingSent();
});

it('reports a failing onEnd hook and still sends the last event', function () {
    [$user, $acme] = userInOrganization();
    Exceptions::fake();
    $this->ai->pushStream(['Fine']);
    $stream = new AiEventStream(
        $acme,
        app(AiStreamSlots::class)->acquire($acme, $user),
        textRequest(),
        onEnd: fn () => throw new RuntimeException('hook broke'),
    );

    $response = TestResponse::fromBaseResponse($stream->toResponse(request()));

    expect(sseEvents($response))->toBe([
        ['event' => 'delta', 'data' => ['text' => 'Fine']],
        ['event' => 'done', 'data' => ['model' => config('ai.model')]],
    ]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'hook broke');
});

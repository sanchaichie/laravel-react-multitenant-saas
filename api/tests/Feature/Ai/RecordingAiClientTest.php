<?php

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use App\Models\AiUsage;
use Illuminate\Support\Facades\Log;

function usageRequest(?int $userId = null): AiRequest
{
    return new AiRequest(feature: 'test_feature', system: 'S', messages: [['role' => 'user', 'content' => 'Hi']], userId: $userId);
}

function aiResponse(string $model = 'claude-haiku-5-5', int $in = 1_000_000, int $out = 2_000_000): AiResponse
{
    return new AiResponse(text: 'ok', json: null, model: $model, inputTokens: $in, outputTokens: $out, stopReason: 'end_turn');
}

it('records a successful call with tokens and cost in the current organization', function () {
    [$user, $acme] = userInOrganization();
    $this->ai->push(aiResponse('claude-haiku-5-5', 1_000_000, 2_000_000));

    inOrganization($acme, fn () => app(AiClient::class)->generate(usageRequest($user->id)));

    $usage = AiUsage::withoutGlobalScopes()->sole();
    expect($usage->organization_id)->toBe($acme->id)
        ->and($usage->user_id)->toBe($user->id)
        ->and($usage->feature)->toBe('test_feature')
        ->and($usage->model)->toBe('claude-haiku-5-5')
        ->and($usage->input_tokens)->toBe(1_000_000)
        ->and($usage->output_tokens)->toBe(2_000_000)
        // 1M in at $0.10 + 2M out at $0.50
        ->and($usage->cost_usd)->toBe('1.100000')
        ->and($usage->status)->toBe('succeeded')
        ->and($usage->error_code)->toBeNull();
});

it('prices the model that answered, not the configured one', function () {
    [, $acme] = userInOrganization();
    $this->ai->push(aiResponse('claude-sonnet-5-5', 1_000_000, 0));

    inOrganization($acme, fn () => app(AiClient::class)->generate(usageRequest()));

    expect(AiUsage::withoutGlobalScopes()->sole())
        ->model->toBe('claude-sonnet-5-5')
        ->cost_usd->toBe('2.000000');
});

it('records failed calls, keeping tokens when the model answered, and rethrows', function () {
    [, $acme] = userInOrganization();
    $this->ai->push(new AiRequestFailed('declined', 'refusal', aiResponse('claude-haiku-5-5', 500, 10)));
    $this->ai->push(new AiUnavailable('busy', 'http_529'));

    inOrganization($acme, function () {
        expect(fn () => app(AiClient::class)->generate(usageRequest()))->toThrow(AiRequestFailed::class)
            ->and(fn () => app(AiClient::class)->generate(usageRequest()))->toThrow(AiUnavailable::class);
    });

    $rows = AiUsage::withoutGlobalScopes()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->only(['status', 'error_code', 'input_tokens', 'output_tokens']))
        ->toBe(['status' => 'failed', 'error_code' => 'refusal', 'input_tokens' => 500, 'output_tokens' => 10])
        ->and($rows[1]->only(['status', 'error_code', 'input_tokens', 'output_tokens']))
        ->toBe(['status' => 'failed', 'error_code' => 'http_529', 'input_tokens' => 0, 'output_tokens' => 0]);
});

it('prices a dated model id as its base model', function () {
    Log::spy();
    [, $acme] = userInOrganization();
    $this->ai->push(aiResponse('claude-haiku-5-5-20261001', 1_000_000, 2_000_000));

    inOrganization($acme, fn () => app(AiClient::class)->generate(usageRequest()));

    expect(AiUsage::withoutGlobalScopes()->sole()->cost_usd)->toBe('1.100000');
    Log::shouldNotHaveReceived('warning');
});

it('prices an unknown model as the configured one, with a warning', function () {
    Log::spy();
    config(['ai.model' => 'claude-sonnet-5-5']);
    [, $acme] = userInOrganization();
    // Sonnet 5.5: $2 in / $10 out per 1M tokens.
    $this->ai->push(aiResponse('claude-mystery-1', 1_000_000, 1_000_000));

    inOrganization($acme, fn () => app(AiClient::class)->generate(usageRequest()));

    expect(AiUsage::withoutGlobalScopes()->sole()->cost_usd)->toBe('12.000000');
    Log::shouldHaveReceived('warning')->once();
});

it('records no cost when neither model is priced', function () {
    config(['ai.model' => 'claude-unlisted']);
    [, $acme] = userInOrganization();
    $this->ai->push(aiResponse('claude-mystery-1', 10, 10));

    inOrganization($acme, fn () => app(AiClient::class)->generate(usageRequest()));

    expect(AiUsage::withoutGlobalScopes()->sole()->cost_usd)->toBeNull();
});

it('resolves the fake provider in tests, never the real one', function () {
    expect(app('ai.provider'))->toBe($this->ai);
});

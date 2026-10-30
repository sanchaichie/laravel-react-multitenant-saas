<?php

namespace App\Ai\Clients;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use App\Models\AiUsage;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wraps the provider so every call, failed or not, is written to ai_usage for
 * the current organization. Features never record usage themselves.
 */
class RecordingAiClient implements AiClient
{
    /**
     * @param  array<string, array{input: float, output: float}>  $pricing  USD per 1M tokens.
     */
    public function __construct(
        private readonly AiClient $inner,
        private readonly string $model,
        private readonly array $pricing,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        return $this->recorded($request, fn () => $this->inner->generate($request));
    }

    // A stopped or broken-off stream is recorded with the tokens counted so far.
    public function stream(AiRequest $request, Closure $onText): AiResponse
    {
        return $this->recorded($request, fn () => $this->inner->stream($request, $onText));
    }

    /**
     * @param  Closure(): AiResponse  $call
     */
    private function recorded(AiRequest $request, Closure $call): AiResponse
    {
        try {
            $response = $call();
        } catch (AiRequestFailed|AiUnavailable $e) {
            $this->record($request, $e->response, 'failed', $e->errorCode);

            throw $e;
        } catch (Throwable $e) {
            $this->record($request, null, 'failed', 'exception');

            throw $e;
        }

        // A stream the caller stopped still succeeded; the code tells it apart.
        $this->record($request, $response, 'succeeded', $response->stopReason === 'aborted' ? 'aborted' : null);

        return $response;
    }

    private function record(AiRequest $request, ?AiResponse $response, string $status, ?string $errorCode): void
    {
        $model = $response->model ?? $this->model;
        $inputTokens = $response->inputTokens ?? 0;
        $outputTokens = $response->outputTokens ?? 0;

        $usage = new AiUsage;
        $usage->forceFill([
            'user_id' => $request->userId,
            'feature' => $request->feature,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $this->cost($model, $inputTokens, $outputTokens),
            'status' => $status,
            'error_code' => $errorCode,
        ]);
        // organization_id comes from CurrentOrganization (BelongsToOrganization).
        $usage->save();
    }

    private function cost(string $model, int $inputTokens, int $outputTokens): ?string
    {
        $price = $this->price($model);

        if ($price === null) {
            return null;
        }

        $cost = ($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000;

        return number_format($cost, 6, '.', '');
    }

    /**
     * The model's price. Plan 11's budget sums cost_usd, so an unpriced call must
     * not count as free: a dated id ("claude-haiku-5-5-20261001") uses its base
     * model's price, and anything else the configured model's. Null (and a warning)
     * only when neither is priced.
     *
     * @return array{input: float, output: float}|null
     */
    private function price(string $model): ?array
    {
        if (isset($this->pricing[$model])) {
            return $this->pricing[$model];
        }

        $base = collect($this->pricing)
            ->filter(fn (array $price, string $priced) => str_starts_with($model, $priced.'-'))
            ->sortByDesc(fn (array $price, string $priced) => strlen($priced))
            ->first();

        if ($base !== null) {
            return $base;
        }

        Log::warning("No AI pricing configured for model [{$model}]; priced as [{$this->model}].");

        return $this->pricing[$this->model] ?? null;
    }
}

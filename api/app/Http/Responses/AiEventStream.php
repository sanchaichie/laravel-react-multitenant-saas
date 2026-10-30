<?php

namespace App\Http\Responses;

use App\Ai\AiRequest;
use App\Ai\AiStreamSlot;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Streams one AI answer as server-sent events:
 *  - delta {text}: the next piece of text;
 *  - done {model}: the answer is complete;
 *  - error {message}: it failed part-way (the text so far is not a valid answer).
 *
 * The status is always 200 once streaming starts, so check permissions, validation,
 * the budget and the slot before returning this. It runs as the organization that
 * authorized it (even if the user switches meanwhile) and releases its slot
 * (AiStreamSlots) at the end. Not Laravel's eventStream(): that needs a generator,
 * and the AI client reports text through a callback.
 */
class AiEventStream implements Responsable
{
    /**
     * @param  int|null  $maxLength  Characters; a longer answer ends with an error instead of done.
     * @param  (Closure(string, string, ?string): void)|null  $onEnd  See end(); e.g. to save the answer.
     */
    public function __construct(
        private readonly Organization $organization,
        private readonly AiStreamSlot $slot,
        private readonly AiRequest $request,
        private readonly ?int $maxLength = null,
        private readonly ?Closure $onEnd = null,
    ) {}

    public function toResponse($request): StreamedResponse
    {
        return response()->stream(fn () => $this->run(), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function run(): void
    {
        // Keep running when the browser goes away, so the call is stopped and recorded
        // (ai_usage, onEnd) by us instead of PHP ending the script mid-call.
        $ignoreAbort = ignore_user_abort(true);

        try {
            app(CurrentOrganization::class)->run($this->organization, fn () => $this->answer());
        } finally {
            ignore_user_abort($ignoreAbort);
            $this->slot->release();
        }
    }

    private function answer(): void
    {
        $text = '';
        $tooLong = false;

        try {
            $response = app(AiClient::class)->stream($this->request, function (string $piece) use (&$text, &$tooLong) {
                // Stop as soon as the answer can't be used: no point streaming (and paying for) the rest.
                if ($this->maxLength !== null && mb_strlen($text.$piece) > $this->maxLength) {
                    $tooLong = true;

                    return false;
                }

                $text .= $piece;
                $this->send('delta', ['text' => $piece]);

                return connection_aborted() === 0;
            });
        } catch (AiBudgetExceeded) {
            $this->fail($text, AiBudgetExceeded::MESSAGE);

            return;
        } catch (AiUnavailable) {
            $this->fail($text, 'The AI service is busy or unreachable. Try again in a moment.');

            return;
        } catch (AiRequestFailed $e) {
            $this->fail($text, match ($e->errorCode) {
                'refusal' => 'The AI declined this request.',
                'max_tokens' => 'The answer was too long. Try again with shorter text.',
                default => 'The AI request failed.',
            });

            return;
        } catch (Throwable $e) {
            report($e);
            $this->fail($text, 'Something went wrong. Please try again.');

            return;
        }

        if ($tooLong) {
            $this->fail($text, "The answer was longer than {$this->maxLength} characters. Try again.", $response->model);

            return;
        }

        // The browser went away: keep what arrived; nobody is listening for the last event.
        if ($response->stopReason === 'aborted') {
            $this->end($text, 'stopped', $response->model);

            return;
        }

        $this->end($text, 'done', $response->model);
        $this->send('done', ['model' => $response->model]);
    }

    private function fail(string $text, string $message, ?string $model = null): void
    {
        $this->end($text, 'error', $model);
        $this->send('error', ['message' => $message]);
    }

    /**
     * Calls onEnd once, before the last event: with the text the browser received, the
     * outcome ("done", "error", or "stopped" when the browser went away) and the model
     * if known. It runs as the organization. A failing hook is reported, and the browser
     * still gets the last event.
     */
    private function end(string $text, string $outcome, ?string $model): void
    {
        if ($this->onEnd === null) {
            return;
        }

        try {
            ($this->onEnd)($text, $outcome, $model);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $event, array $data): void
    {
        echo "event: {$event}\ndata: ".json_encode($data, JSON_THROW_ON_ERROR)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}

<?php

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;
use Closure;

/**
 * The only way the app talks to an AI provider. Resolved from the container,
 * already wrapped so every call is recorded in ai_usage.
 */
interface AiClient
{
    /**
     * @throws AiUnavailable when retrying later may work (rate limit, 5xx, timeout)
     * @throws AiRequestFailed when retrying won't help (bad request, refusal, invalid output)
     */
    public function generate(AiRequest $request): AiResponse;

    /**
     * The answer as it is written: $onText gets each piece of text, and returning
     * false from it stops the call (stop reason "aborted", with what arrived so far).
     * Plain text only: a request with a schema is refused. Exceptions carry the
     * partial response when some of the answer had arrived.
     *
     * @param  Closure(string): (bool|null|void)  $onText
     *
     * @throws AiUnavailable when retrying later may work
     * @throws AiRequestFailed when retrying won't help
     */
    public function stream(AiRequest $request, Closure $onText): AiResponse;
}

<?php

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Exceptions\AiUnavailable;

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
}

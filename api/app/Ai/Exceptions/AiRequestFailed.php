<?php

namespace App\Ai\Exceptions;

use App\Ai\AiResponse;
use RuntimeException;

/**
 * The call can't succeed as is (bad request, refusal, cut off, invalid output).
 * Don't retry. $response is set when the model did answer, so its tokens are still recorded.
 */
class AiRequestFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?AiResponse $response = null,
    ) {
        parent::__construct($message);
    }
}

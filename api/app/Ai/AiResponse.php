<?php

namespace App\Ai;

final readonly class AiResponse
{
    /**
     * @param  string  $model  The model that actually answered (may differ after a fallback).
     * @param  array<string, mixed>|null  $json  Decoded output when the request had a schema. Still untrusted: validate it.
     */
    public function __construct(
        public string $text,
        public ?array $json,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $stopReason,
    ) {}
}

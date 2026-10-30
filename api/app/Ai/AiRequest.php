<?php

namespace App\Ai;

/**
 * One call to the model. Build these in a prompt class (app/Ai/Prompts), not inline.
 */
final readonly class AiRequest
{
    /**
     * @param  string  $feature  Recorded in ai_usage, e.g. "task_summary".
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $schema  JSON schema for structured output; the response's json is then set.
     * @param  string|null  $effort  low, medium or high; null uses the model's default.
     * @param  int|null  $userId  Who asked for it, recorded in ai_usage.
     */
    public function __construct(
        public string $feature,
        public string $system,
        public array $messages,
        public ?array $schema = null,
        public ?int $maxTokens = null,
        public ?string $effort = null,
        public ?int $userId = null,
    ) {}
}

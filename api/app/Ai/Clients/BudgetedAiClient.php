<?php

namespace App\Ai\Clients;

use App\Ai\AiBudget;
use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Exceptions\NoCurrentOrganization;
use App\Support\CurrentOrganization;
use Closure;

/**
 * Refuses calls once the current organization's monthly budget is used up
 * (AiBudgetExceeded), before they reach the provider or ai_usage. Without a
 * current organization it refuses too: the call would cost money nobody is billed for.
 */
class BudgetedAiClient implements AiClient
{
    public function __construct(
        private readonly AiClient $inner,
        private readonly AiBudget $budget,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $this->ensureAllowed();

        return $this->inner->generate($request);
    }

    public function stream(AiRequest $request, Closure $onText): AiResponse
    {
        $this->ensureAllowed();

        return $this->inner->stream($request, $onText);
    }

    private function ensureAllowed(): void
    {
        $organization = $this->currentOrganization->get() ?? throw NoCurrentOrganization::forAiCall();
        $this->budget->ensureAvailable($organization);
    }
}

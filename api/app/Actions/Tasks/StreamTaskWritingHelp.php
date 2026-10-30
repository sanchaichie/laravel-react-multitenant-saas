<?php

namespace App\Actions\Tasks;

use App\Ai\AiBudget;
use App\Ai\AiStreamSlots;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Prompts\TaskWritingPrompt;
use App\Enums\WritingHelpMode;
use App\Http\Responses\AiEventStream;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

/**
 * A rewritten task description, streamed back to the form. Nothing is saved: the
 * user accepts it into the form and saves the task as usual.
 */
class StreamTaskWritingHelp
{
    public function __construct(
        private readonly AiBudget $budget,
        private readonly TaskWritingPrompt $prompt,
        private readonly AiStreamSlots $slots,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    /**
     * Returns the response, not just the request: the stream must run as this
     * organization and release its slot when it ends.
     *
     * @param  array{mode: string, title?: string|null, description?: string|null}  $input
     *
     * @throws AiBudgetExceeded before streaming starts, so it's still a 402
     * @throws ThrottleRequestsException 429 when the organization, the user or the server has too many streams running
     */
    public function handle(array $input, User $user): AiEventStream
    {
        $organization = $this->currentOrganization->get();
        $this->budget->ensureAvailable($organization);
        $request = $this->prompt->request(
            WritingHelpMode::from($input['mode']),
            $input['title'] ?? null,
            $input['description'] ?? null,
            $user->id,
        );

        // Last: nothing after it can throw before the stream takes over releasing it.
        return new AiEventStream($organization, $this->slots->acquire($organization, $user), $request, TaskWritingPrompt::MAX_LENGTH);
    }
}

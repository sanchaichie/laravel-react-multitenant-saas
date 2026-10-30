<?php

namespace App\Actions\TaskChat;

use App\Ai\AiBudget;
use App\Ai\AiStreamSlots;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Ai\Prompts\TaskChatPrompt;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Http\Responses\AiEventStream;
use App\Models\Task;
use App\Models\TaskChatMessage;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;

/**
 * Saves the user's question with an empty answer after it, then streams the answer
 * into that row when it ends: complete, stopped (the user cancelled; the text so far)
 * or failed. Saving the answer row up front keeps the order right when the next
 * question arrives before the server notices a stop, and lets Clear remove it. Until
 * it ends it reads as stopped, which is also what a worker that dies mid-answer leaves.
 */
class SendTaskChatMessage
{
    public function __construct(
        private readonly AiBudget $budget,
        private readonly TaskChatPrompt $prompt,
        private readonly AiStreamSlots $slots,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @throws AiBudgetExceeded before anything is saved, so it's still a 402
     * @throws ThrottleRequestsException 429 when too many streams run (nothing is saved)
     */
    public function handle(Task $task, User $user, string $message): AiEventStream
    {
        $organization = $this->currentOrganization->get();
        $this->budget->ensureAvailable($organization);

        // Taken before saving, so a refused question isn't left in the history; given
        // back if saving fails, since the stream that would release it never starts.
        $slot = $this->slots->acquire($organization, $user);

        try {
            [$answer, $request] = DB::transaction(function () use ($task, $user, $message) {
                // Lock the task first, as summaries do: sends on it run one at a time, so
                // each question is followed by its own answer, and a task deleted meanwhile
                // is a 404 here instead of a foreign key error. Scoped, so never another
                // organization's.
                Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

                $this->save($task, $user, ChatMessageRole::User, $message, ChatMessageStatus::Complete);

                $history = TaskChatMessage::query()->conversation($task, $user)
                    ->latest('id')->limit(TaskChatPrompt::HISTORY)->get()->reverse()->values();
                $answer = $this->save($task, $user, ChatMessageRole::Assistant, '', ChatMessageStatus::Stopped);

                return [$answer, $this->prompt->request($task, $task->approvedSummary()->first(), $history, $user->id)];
            });
        } catch (\Throwable $e) {
            $slot->release();

            throw $e;
        }

        // An update by id, not a save: if the chat was cleared meanwhile, nothing comes back.
        return new AiEventStream($organization, $slot, $request, onEnd: function (string $text, string $outcome, ?string $model) use ($answer) {
            TaskChatMessage::query()->whereKey($answer->id)->update([
                'content' => $text,
                'status' => match ($outcome) {
                    'done' => ChatMessageStatus::Complete,
                    'stopped' => ChatMessageStatus::Stopped,
                    default => ChatMessageStatus::Failed,
                },
                'model' => $model,
                'updated_at' => now(),
            ]);
        });
    }

    private function save(Task $task, User $user, ChatMessageRole $role, string $content, ChatMessageStatus $status): TaskChatMessage
    {
        $chatMessage = new TaskChatMessage;
        $chatMessage->forceFill([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'role' => $role,
            'content' => $content,
            'status' => $status,
        ]);
        // organization_id comes from CurrentOrganization (BelongsToOrganization).
        $chatMessage->save();

        return $chatMessage;
    }
}

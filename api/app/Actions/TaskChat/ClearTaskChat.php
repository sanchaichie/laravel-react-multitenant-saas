<?php

namespace App\Actions\TaskChat;

use App\Models\Task;
use App\Models\TaskChatMessage;
use App\Models\User;

/**
 * Deletes the user's own conversation about the task; other members' chats stay.
 */
class ClearTaskChat
{
    public function handle(Task $task, User $user): void
    {
        TaskChatMessage::query()->conversation($task, $user)->delete();
    }
}

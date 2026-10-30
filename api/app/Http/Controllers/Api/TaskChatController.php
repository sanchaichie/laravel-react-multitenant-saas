<?php

namespace App\Http\Controllers\Api;

use App\Actions\TaskChat\ClearTaskChat;
use App\Actions\TaskChat\SendTaskChatMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskChat\SendTaskChatMessageRequest;
use App\Http\Resources\TaskChatMessageResource;
use App\Http\Responses\AiEventStream;
use App\Models\Task;
use App\Models\TaskChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The current user's own AI chat about a task. {task} resolves through the
 * organization scope, so another organization's task is a 404; other members'
 * chats are never read here.
 */
class TaskChatController extends Controller
{
    private const SHOWN = 50;

    // The last messages, oldest first.
    public function index(Request $request, Task $task): AnonymousResourceCollection
    {
        Gate::authorize('chat', $task);

        $messages = TaskChatMessage::query()->conversation($task, $request->user())
            ->latest('id')->limit(self::SHOWN)->get()->reverse()->values();

        return TaskChatMessageResource::collection($messages);
    }

    // Streamed (text/event-stream); see AiEventStream.
    public function store(SendTaskChatMessageRequest $request, Task $task, SendTaskChatMessage $action): AiEventStream
    {
        Gate::authorize('chat', $task);

        return $action->handle($task, $request->user(), $request->validated('message'));
    }

    public function destroy(Request $request, Task $task, ClearTaskChat $action): Response
    {
        Gate::authorize('chat', $task);

        $action->handle($task, $request->user());

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\StreamTaskWritingHelp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskWritingHelpRequest;
use App\Http\Responses\AiEventStream;
use App\Models\Task;
use Illuminate\Support\Facades\Gate;

/**
 * AI writing help for the task form, streamed as server-sent events
 * (AiEventStream). {task} resolves through the organization scope, so another
 * organization's task is a 404.
 */
class TaskWritingHelpController extends Controller
{
    // For the New task form.
    public function store(TaskWritingHelpRequest $request, StreamTaskWritingHelp $action): AiEventStream
    {
        Gate::authorize('writeWithAi', Task::class);

        return $action->handle($request->validated(), $request->user());
    }

    // For the edit form of an existing task.
    public function update(TaskWritingHelpRequest $request, Task $task, StreamTaskWritingHelp $action): AiEventStream
    {
        Gate::authorize('writeWithAi', $task);

        return $action->handle($request->validated(), $request->user());
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\UpdateTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\ListTasksRequest;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Tasks of the current organization. {task} binds through BelongsToOrganization's
 * scope, so another organization's task is a 404.
 */
class TaskController extends Controller
{
    public function index(ListTasksRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);

        $tasks = Task::query()
            ->with(['assignee', 'creator'])
            ->filter($request->filters(), $request->user())
            ->sorted($request->sort())
            ->paginate(Task::PER_PAGE)
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request, CreateTask $action): JsonResponse
    {
        Gate::authorize('create', Task::class);

        $task = $action->handle($request->user(), $request->validated());

        return (new TaskResource($task->load(['assignee', 'creator'])))->response()->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return new TaskResource($task->load(['assignee', 'creator']));
    }

    public function update(UpdateTaskRequest $request, Task $task, UpdateTask $action): TaskResource
    {
        Gate::authorize('update', $task);

        return new TaskResource($action->handle($task, $request->validated())->load(['assignee', 'creator']));
    }

    public function destroy(Task $task): Response
    {
        Gate::authorize('delete', $task);

        $task->delete();

        return response()->noContent();
    }
}

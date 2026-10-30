<?php

namespace App\Http\Controllers\Api;

use App\Actions\TaskSummaries\RequestTaskSummary;
use App\Actions\TaskSummaries\ReviewTaskSummary;
use App\Enums\TaskSummaryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TaskSummaries\ApproveTaskSummaryRequest;
use App\Http\Resources\TaskSummaryResource;
use App\Models\Task;
use App\Models\TaskSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AI summaries of a task of the current organization. {task} and {summary} bind
 * through the organization scope, and {summary} must belong to {task} (scoped
 * bindings), so anything else is a 404.
 */
class TaskSummaryController extends Controller
{
    /**
     * The latest summary (any status), the approved one (the task's summary until a
     * newer one is approved), either may be null, and whether you may request one.
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        Gate::authorize('view', [TaskSummary::class, $task]);

        $latest = $task->latestSummary()->with(['requester', 'reviewer'])->first();
        $approved = $latest?->status === TaskSummaryStatus::Approved
            ? $latest
            : $task->approvedSummary()->with(['requester', 'reviewer'])->first();

        $resource = fn (?TaskSummary $summary) => $summary === null ? null
            : (new TaskSummaryResource($summary->setRelation('task', $task)))->resolve($request);

        return response()->json([
            'data' => $resource($latest),
            'approved' => $resource($approved),
            // The route also needs a verified email: no button that would only get a 403.
            'can' => ['regenerate' => $request->user()->hasVerifiedEmail() && $request->user()->can('create', [TaskSummary::class, $task])],
        ]);
    }

    public function store(Request $request, Task $task, RequestTaskSummary $action): JsonResponse
    {
        Gate::authorize('create', [TaskSummary::class, $task]);

        $summary = $action->handle($task, $request->user());

        return (new TaskSummaryResource($summary->setRelation('task', $task)))->response()->setStatusCode(202);
    }

    public function approve(ApproveTaskSummaryRequest $request, Task $task, TaskSummary $summary, ReviewTaskSummary $action): TaskSummaryResource
    {
        return $this->review($request, $task, $summary, $action, true, $request->validated());
    }

    public function reject(Request $request, Task $task, TaskSummary $summary, ReviewTaskSummary $action): TaskSummaryResource
    {
        return $this->review($request, $task, $summary, $action, false);
    }

    /**
     * @param  array<string, mixed>  $edits
     */
    private function review(Request $request, Task $task, TaskSummary $summary, ReviewTaskSummary $action, bool $approve, array $edits = []): TaskSummaryResource
    {
        Gate::authorize('review', $summary->setRelation('task', $task));

        $summary = $action->handle($summary, $request->user(), $approve, $edits);

        return new TaskSummaryResource($summary->setRelation('task', $task));
    }
}

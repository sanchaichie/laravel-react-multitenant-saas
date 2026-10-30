<?php

namespace App\Http\Resources;

use App\Actions\TaskSummaries\RequestTaskSummary;
use App\Ai\Prompts\TaskSummaryPrompt;
use App\Enums\TaskSummaryStatus;
use App\Models\TaskSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Set the `task` relation before resolving: `stale` and `can` need it.
 *
 * @mixin TaskSummary
 */
class TaskSummaryResource extends JsonResource
{
    // Error codes are internal; people get a short, generic reason.
    private const ERROR_MESSAGES = [
        'refusal' => 'The AI declined to summarize this task.',
        'invalid_output' => 'The AI returned a summary we could not use.',
        'invalid_json' => 'The AI returned a summary we could not use.',
        'max_tokens' => 'The AI returned a summary we could not use.',
        'unavailable' => 'The AI service was unavailable. Try again later.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $task = $this->resource->task;
        $gate = Gate::forUser($request->user());

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'key_points' => $this->key_points ?? [],
            'model' => $this->model,
            // The task's title or description changed since this summary was written.
            'stale' => $this->source_hash !== null && $task !== null
                && ! hash_equals($this->source_hash, TaskSummaryPrompt::sourceHash($task)),
            // Pending so long its job is likely lost: asking again replaces it.
            'stuck' => $this->status === TaskSummaryStatus::Pending
                && $this->created_at->lte(now()->subMinutes(RequestTaskSummary::STALE_AFTER_MINUTES)),
            'error' => $this->status === TaskSummaryStatus::Failed
                ? (self::ERROR_MESSAGES[$this->error] ?? 'The summary could not be generated.')
                : null,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
            'can' => [
                'review' => $gate->allows('review', $this->resource),
            ],
        ];
    }
}

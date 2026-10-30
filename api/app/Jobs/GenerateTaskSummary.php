<?php

namespace App\Jobs;

use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiRequestFailed;
use App\Ai\Prompts\TaskSummaryPrompt;
use App\Enums\TaskSummaryStatus;
use App\Models\TaskSummary;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Turns a pending TaskSummary into a draft. The output is validated before it's
 * saved, and stays a draft until someone approves it.
 */
class GenerateTaskSummary extends OrganizationJob
{
    public function __construct(int $organizationId, public readonly int $summaryId, ?int $userId = null)
    {
        parent::__construct($organizationId, $userId);
    }

    public function middleware(): array
    {
        return [...parent::middleware(), new RateLimited('ai')];
    }

    public function handle(AiClient $ai, TaskSummaryPrompt $prompt): void
    {
        // Scoped to this job's organization; gone or already handled means nothing to do.
        $summary = TaskSummary::query()->with('task')->find($this->summaryId);

        if ($summary === null || $summary->status !== TaskSummaryStatus::Pending || $summary->task === null) {
            return;
        }

        // AiUnavailable isn't caught: the job is retried with backoff.
        try {
            $response = $ai->generate($prompt->request($summary->task, $this->userId));
        } catch (AiRequestFailed $e) {
            $this->markFailed($summary, $e->errorCode);
            $this->fail($e);

            return;
        }

        $validator = Validator::make($response->json ?? [], TaskSummaryPrompt::rules());

        if ($validator->fails()) {
            $this->markFailed($summary, 'invalid_output');
            $this->fail(new AiRequestFailed('Summary output failed validation: '.$validator->errors()->first(), 'invalid_output', $response));

            return;
        }

        $output = $validator->validated();

        // Saved only if still pending: a newer request may have given up on this one meanwhile.
        DB::transaction(function () use ($summary, $output, $response) {
            $locked = TaskSummary::query()->whereKey($summary->id)->lockForUpdate()->first();

            if ($locked?->status !== TaskSummaryStatus::Pending) {
                return;
            }

            $locked->forceFill([
                'status' => TaskSummaryStatus::Draft,
                'summary' => trim($output['summary']),
                'key_points' => array_values(array_map(trim(...), $output['key_points'])),
                'model' => $response->model,
                'error' => null,
                // Of the task as it was sent, which may differ from when it was requested.
                'source_hash' => TaskSummaryPrompt::sourceHash($summary->task),
            ])->save();
        });
    }

    /**
     * Retries used up or timed out.
     */
    public function failed(?Throwable $exception): void
    {
        $this->inOrganization(function () {
            $summary = TaskSummary::query()->find($this->summaryId);

            if ($summary?->status === TaskSummaryStatus::Pending) {
                $this->markFailed($summary, 'unavailable');
            }
        });
    }

    private function markFailed(TaskSummary $summary, string $errorCode): void
    {
        // Only a pending row: a newer request may have retired it already.
        TaskSummary::query()->whereKey($summary->id)->where('status', TaskSummaryStatus::Pending)
            ->update(['status' => TaskSummaryStatus::Failed, 'error' => $errorCode, 'updated_at' => now()]);
    }
}

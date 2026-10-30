<?php

namespace App\Console\Commands;

use App\Actions\TaskSummaries\RequestTaskSummary;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task;
use App\Support\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backfill: queue summaries for tasks that have a description but never had a
 * summary (e.g. created before this feature). Each organization runs in its own
 * scope. Only tasks whose creator is still a member allowed to edit them (and so to
 * ask for a summary), with a verified email, are summarized, as on the task page.
 */
class SummarizeTasks extends Command
{
    protected $signature = 'ai:summarize-tasks
        {--organization=* : Only these organization ids (default: all)}
        {--limit=50 : Most summaries to queue in total (the worker runs AI_RATE_LIMIT_PER_MINUTE per organization a minute)}
        {--dry-run : Only count the tasks that would be summarized}';

    protected $description = 'Queue AI summaries for tasks that have a description and no summary';

    public function handle(CurrentOrganization $currentOrganization, RequestTaskSummary $requestSummary): int
    {
        $limit = (int) $this->option('limit');
        $ids = array_map(intval(...), (array) $this->option('organization'));
        $dryRun = (bool) $this->option('dry-run');

        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::INVALID;
        }

        $remaining = $limit;
        $total = 0;

        $organizations = Organization::query()->when($ids !== [], fn ($query) => $query->whereKey($ids))->lazyById();

        foreach ($organizations as $organization) {
            if ($remaining === 0) {
                break;
            }

            $count = $currentOrganization->run($organization, function () use ($organization, $requestSummary, $remaining, $dryRun) {
                $label = "{$organization->name} (#{$organization->id})";

                // Scoped to this organization by BelongsToOrganization.
                $candidates = fn (): Builder => Task::query()
                    ->whereNotNull('description')
                    ->where('description', '!=', '')
                    ->whereDoesntHave('summaries');

                // A creator may edit their own task with tasks.create (or any with tasks.manage).
                $roleIds = Role::query()->get()
                    ->filter(fn (Role $role) => $role->can(Permission::TasksCreate) || $role->can(Permission::TasksManage))
                    ->modelKeys();
                // A subquery, not a list of ids; verified emails only, as for any AI request.
                $allowedCreatorIds = $organization->members()->wherePivotIn('role_id', $roleIds)
                    ->whereNotNull('users.email_verified_at')->select('users.id');

                $tasks = $candidates()->whereIn('created_by', $allowedCreatorIds)->with('creator')->orderBy('id')->limit($remaining)->get();
                $notAllowed = $candidates()->where(fn ($query) => $query->whereNull('created_by')->orWhereNotIn('created_by', $allowedCreatorIds))->count();
                $skipped = $notAllowed > 0 ? ", skipped {$notAllowed} whose creator left, can't edit them or isn't verified" : '';

                if ($tasks->isEmpty()) {
                    if ($notAllowed > 0) {
                        $this->line("{$label}: nothing to queue{$skipped}.");
                    }

                    return 0;
                }

                if ($dryRun) {
                    $this->line("{$label}: would queue {$tasks->count()}{$skipped}.");

                    return $tasks->count();
                }

                foreach ($tasks as $task) {
                    // Credited to the creator; the audit log shows it as a system request.
                    $requestSummary->handle($task, $task->creator, source: 'backfill');
                }

                $this->line("{$label}: queued {$tasks->count()}{$skipped}.");

                return $tasks->count();
            });

            $remaining -= $count;
            $total += $count;
        }

        $this->info($dryRun ? "Would queue {$total} summaries." : "Queued {$total} summaries.");

        if (! $dryRun && $total > 0) {
            $this->line('The queue worker generates them (php artisan queue:work), within the per-organization AI rate limit.');
        }

        return self::SUCCESS;
    }
}

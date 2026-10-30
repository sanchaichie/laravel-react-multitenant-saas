<?php

namespace App\Jobs;

use App\Jobs\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Base for queued work that belongs to one organization. handle() runs inside
 * CurrentOrganization::run(), so tenant-owned models are scoped as in a request.
 * The organization id is set by server code when dispatching, never from input.
 *
 * Timeouts nest: AI HTTP timeout (60s) < $timeout (75s) < queue retry_after (90s).
 */
abstract class OrganizationJob implements ShouldQueue
{
    use Queueable;

    // Retried until retryUntil() rather than a number of tries: rate-limited releases
    // count as attempts, and a backfill can queue many jobs behind one limit.
    public int $maxExceptions = 3;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $organizationId,
        public readonly ?int $userId = null,
    ) {}

    /**
     * Keep trying (releases and retries) until then. RequestTaskSummary::STALE_AFTER_MINUTES
     * stays above it, so a job still trying is never treated as lost.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    /**
     * Seconds to wait before retrying after an exception.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetCurrentOrganization];
    }

    /**
     * Run code in this job's organization outside handle(), e.g. in failed(),
     * which doesn't go through job middleware. Nothing runs if it's gone.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    protected function inOrganization(Closure $callback): mixed
    {
        $organization = Organization::find($this->organizationId);

        if ($organization === null) {
            return null;
        }

        return app(CurrentOrganization::class)->run($organization, $callback);
    }
}

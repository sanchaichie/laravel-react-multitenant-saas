<?php

namespace App\Jobs\Middleware;

use App\Jobs\OrganizationJob;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;

/**
 * Runs an OrganizationJob inside its organization, so BelongsToOrganization scopes
 * and creates rows there.
 */
class SetCurrentOrganization
{
    public function handle(OrganizationJob $job, Closure $next): mixed
    {
        $organization = Organization::find($job->organizationId);

        // Deleted since dispatch: nothing left to work on.
        if ($organization === null) {
            $job->delete();

            return null;
        }

        // run() restores the previous organization afterwards: on the sync queue the
        // job runs inside a request (or another job).
        return app(CurrentOrganization::class)->run($organization, fn () => $next($job));
    }
}

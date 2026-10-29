<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For routes that act on the current organization: refuse when the user has none
 * (or is no longer a member of it).
 *
 * The app also sends X-Organization-Id: the organization it *thinks* is current.
 * It never chooses the organization (that is always the server's current one);
 * it is only a precondition. When another tab switched in the meantime, the
 * request is refused with 409 instead of reading or writing in an organization
 * the user isn't looking at.
 */
class EnsureCurrentOrganization
{
    public const HEADER = 'X-Organization-Id';

    public function __construct(private readonly CurrentOrganization $currentOrganization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $this->currentOrganization->id();

        abort_if($organizationId === null, 403, 'No active organization.');

        $expected = $request->header(self::HEADER);
        if ($expected !== null && $expected !== (string) $organizationId) {
            return response()->json([
                'message' => 'Your current organization changed (in another tab?). Reload to continue.',
                'code' => 'organization_changed',
            ], 409);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For routes a platform admin's "view as user" session must not read (alias
 * `not-impersonating`): task content (summaries, chats) and private account data
 * (devices and their IPs).
 */
class NotImpersonating
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->active()) {
            return response()->json([
                'message' => 'Hidden in the support view.',
                'code' => 'impersonation_hidden',
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For features that reach other people or cost money (invitations, later billing
 * and AI): refuse until the user has verified their email. The code lets the app
 * point at the verification banner.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verify your email address to use this feature.',
                'code' => 'email_unverified',
            ], 403);
        }

        return $next($request);
    }
}

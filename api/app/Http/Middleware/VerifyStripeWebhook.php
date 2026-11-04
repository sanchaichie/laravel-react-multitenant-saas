<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier only checks the signature when a secret is set. Without one, anyone
 * could post a "paid" event, so a missing STRIPE_WEBHOOK_SECRET refuses everything.
 */
class VerifyStripeWebhook extends VerifyWebhookSignature
{
    public function handle($request, Closure $next): Response
    {
        if (blank(config('cashier.webhook.secret'))) {
            Log::error('Stripe webhook refused: STRIPE_WEBHOOK_SECRET is not set.');

            // No detail in the response: it would tell anyone verification is off.
            abort(403);
        }

        return parent::handle($request, $next);
    }
}

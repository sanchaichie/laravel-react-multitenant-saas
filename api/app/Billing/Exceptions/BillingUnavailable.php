<?php

namespace App\Billing\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The payment provider couldn't be reached or refused the call. Nothing changed;
 * the user can try again. The cause is logged, not shown.
 */
class BillingUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Billing is unavailable right now. Try again in a few minutes.',
            'code' => 'billing_unavailable',
        ], 503);
    }
}

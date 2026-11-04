<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

trait UsesCurrentToken
{
    /**
     * The token of this request, to keep when signing out the other devices.
     */
    private function currentToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        // A saved token only (Sanctum::actingAs in tests gives an unsaved stand-in).
        return $token instanceof PersonalAccessToken && $token->exists ? $token : null;
    }
}

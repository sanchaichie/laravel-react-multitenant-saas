<?php

namespace App\Webhooks;

/**
 * Signs a delivery the way receivers verify it (README / docs/api.md): HMAC-SHA256
 * of "{timestamp}.{body}" with the endpoint's secret, sent as
 * X-Webhook-Signature: v1=<hex>. The timestamp lets them refuse old replays.
 */
class WebhookSigner
{
    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(32));
    }
}

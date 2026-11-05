<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Private targets allowed (local and e2e only)
    |--------------------------------------------------------------------------
    |
    | Webhook URLs must be https and resolve only to public addresses, so the
    | server can't be pointed at itself or a private network (SSRF). This lists
    | exact host:port pairs that skip that check and may use http, for a local
    | receiver (servers.sh: 127.0.0.1:8113). The app refuses to boot with it set
    | unless APP_ENV is local or testing.
    |
    */

    'allowed_private_targets' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBHOOKS_ALLOWED_PRIVATE_TARGETS', '')),
    ))),

    // The queue deliveries run on: give it its own workers (`php artisan queue:work
    // --queue=webhooks`), or list it after the others (`--queue=default,webhooks`).
    'queue' => env('WEBHOOK_QUEUE', 'webhooks'),

    // Delivery attempts per organization per minute (beyond that they wait their turn).
    'rate_limit_per_minute' => (int) env('WEBHOOK_RATE_LIMIT_PER_MINUTE', 60),

    // Deliveries older than this many days are deleted by model:prune (daily).
    'retention_days' => (int) env('WEBHOOK_DELIVERY_RETENTION_DAYS', 30),

    // Attempts per delivery, and the wait before each retry (seconds): 1m, 5m, 30m, 2h, 8h.
    'max_attempts' => 6,
    'retry_delays' => [60, 300, 1800, 7200, 28800],

    // Seconds. The job's own timeout (DeliverWebhook) stays above both.
    'timeout' => 10,
    'connect_timeout' => 5,

    'max_endpoints' => 5,

    // Deliveries in a row that used up their attempts before the endpoint is disabled.
    'disable_after_failures' => 15,

    // Bytes of the receiver's answer kept with the delivery.
    'response_body_limit' => 1024,

];

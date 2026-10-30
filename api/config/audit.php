<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Audit log entries older than this many days are deleted by model:prune
    | (scheduled daily in routes/console.php). 0 keeps them forever.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

];

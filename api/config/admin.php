<?php

return [

    /*
    | Platform admin routes need a token that passed a two-factor code (logging in
    | with a code from the app, or reconfirming one) within this many minutes.
    */

    'two_factor_ttl_minutes' => (int) env('ADMIN_TWO_FACTOR_TTL_MINUTES', 720),

    /*
    | Admin actions (reset two-factor, sign out, suspend, make Owner) need a
    | fresher code: within this many minutes.
    */

    'action_two_factor_ttl_minutes' => (int) env('ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES', 15),

    /*
    | A read-only "view as user" session (StartImpersonation) ends after this
    | many minutes. It isn't renewed.
    */

    'impersonation_minutes' => (int) env('ADMIN_IMPERSONATION_MINUTES', 30),

];

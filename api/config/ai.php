<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider and model
    |--------------------------------------------------------------------------
    |
    | All AI calls go through App\Ai\Contracts\AiClient. "anthropic" calls the
    | Claude Messages API; "fake" returns canned responses (tests, e2e runs).
    |
    */

    'provider' => env('AI_PROVIDER', 'anthropic'),

    'model' => env('AI_MODEL', 'claude-haiku-5-5'),

    // Seconds for one HTTP call. Keep it below the job timeout (OrganizationJob, 75s),
    // which in turn stays below the queue's retry_after (90s by default).
    'timeout' => (int) env('AI_TIMEOUT', 60),

    'max_tokens' => 4000,

    /*
    | Server-side refusal fallback: "default", or empty to turn it off. Only sent
    | for the models listed below (Haiku doesn't support it).
    */
    'fallbacks' => env('AI_FALLBACKS') ?: null,

    'fallback_models' => ['claude-opus-5-5', 'claude-sonnet-5-5'],

    // AI calls (and AI requests from the app) per organization per minute.
    'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 5),

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => 'https://api.anthropic.com',
            'version' => '2023-06-01',
        ],
    ],

    /*
    | USD per 1M tokens, for ai_usage.cost_usd. A model missing here is priced as
    | its base model (dated ids), else as the configured one.
    */
    'pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
    ],

];

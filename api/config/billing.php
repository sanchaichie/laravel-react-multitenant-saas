<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Every payment call goes through App\Billing\Contracts\BillingGateway.
    | "stripe" uses Laravel Cashier (keys in config/cashier.php); "fake" makes
    | no network calls and sends checkouts straight back to the billing page
    | (tests, e2e runs). The app refuses "fake" unless APP_ENV is local or testing.
    |
    */

    'provider' => env('BILLING_PROVIDER', 'stripe'),

    // BILLING_PROVIDER=fake only: a checkout also writes what the webhook would
    // (the Pro subscription, or the credit purchase), so e2e flows can show them.
    // Off in tests, which check that checkouts change nothing.
    'fake_complete_checkout' => (bool) env('BILLING_FAKE_COMPLETE_CHECKOUT', false),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | An organization without a valid subscription is on "free". A paid plan
    | needs a Stripe price id. The plan's monthly AI budget is a ceiling: an
    | organization's own budget (organizations.ai_monthly_budget_usd) can only
    | lower it. The free budget is AI_MONTHLY_BUDGET_USD (config/ai.php).
    | display_price is only shown in the app; Stripe charges the price's amount.
    |
    */

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => null,
            'display_price' => '$0 / month',
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => env('STRIPE_PRICE_PRO'),
            'display_price' => env('BILLING_PRO_DISPLAY_PRICE', '$19 / month'),
            'ai_monthly_budget_usd' => (float) env('BILLING_PRO_AI_BUDGET_USD', 50),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI credit packs
    |--------------------------------------------------------------------------
    |
    | One-time purchases (a one-time Stripe price each) that add amount_usd to
    | the organization's AI budget for the rest of the UTC month they're bought
    | in, on any plan. A pack without a price can't be bought.
    |
    */

    'credit_packs' => [
        'credits_10' => [
            'name' => '$10 of AI credits',
            'price' => env('STRIPE_PRICE_CREDITS_10'),
            'display_price' => env('BILLING_CREDITS_10_DISPLAY_PRICE', '$10'),
            'amount_usd' => 10,
        ],
    ],

];

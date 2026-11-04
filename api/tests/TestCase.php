<?php

namespace Tests;

use App\Ai\Clients\FakeAiClient;
use App\Billing\FakeBillingGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected FakeAiClient $ai;

    protected FakeBillingGateway $billing;

    /**
     * No test talks to a real AI provider or to Stripe: the fakes are the default
     * clients, and any HTTP call that isn't faked with Http::fake() fails the test.
     * Webhooks are signed with a test secret (stripeWebhook()).
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.provider' => 'fake']);
        $this->ai = new FakeAiClient(config('ai.model'));
        $this->app->instance(FakeAiClient::class, $this->ai);

        config([
            'billing.provider' => 'fake',
            'billing.fake_complete_checkout' => false,
            'billing.plans.pro.price' => 'price_pro_test',
            'billing.plans.pro.ai_monthly_budget_usd' => 50,
            'billing.credit_packs.credits_10.price' => 'price_credits_test',
            'billing.credit_packs.credits_10.amount_usd' => 10,
            'cashier.secret' => null,
            'cashier.webhook.secret' => 'whsec_test',
        ]);
        $this->billing = new FakeBillingGateway;
        $this->app->instance(FakeBillingGateway::class, $this->billing);

        Http::preventStrayRequests();
    }

    /**
     * Start every test request with fresh scoped services (e.g. CurrentOrganization),
     * like a real request does, instead of reusing what an earlier request cached.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}

<?php

namespace Tests;

use App\Ai\Clients\FakeAiClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected FakeAiClient $ai;

    /**
     * No test talks to a real AI provider: the fake is the default client, and any
     * HTTP call that isn't faked with Http::fake() fails the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.provider' => 'fake']);
        $this->ai = new FakeAiClient(config('ai.model'));
        $this->app->instance(FakeAiClient::class, $this->ai);

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

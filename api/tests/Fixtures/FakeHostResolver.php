<?php

namespace Tests\Fixtures;

use App\Webhooks\Contracts\HostResolver;

/**
 * DNS for tests: only the hosts given (fakeDns()), IP literals as themselves. Bound
 * in TestCase, so no test makes a real lookup.
 */
class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(public array $hosts = []) {}

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[$host] ?? [];
    }
}

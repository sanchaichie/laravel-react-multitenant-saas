<?php

namespace App\Webhooks\Contracts;

/**
 * DNS lookups for webhook URLs, behind an interface so tests can fake them.
 */
interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address the host resolves to (none if it doesn't).
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}

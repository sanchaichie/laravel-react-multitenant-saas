<?php

namespace App\Webhooks;

/**
 * A webhook URL that passed WebhookUrl's checks, with the address it was checked
 * against. Sending pins the connection to that address (CURLOPT_RESOLVE), so a DNS
 * answer that changes between the check and the request can't move it.
 */
final readonly class WebhookTarget
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $address,
    ) {}

    /**
     * The CURLOPT_RESOLVE entry: "host:port:address" (IPv6 in brackets).
     */
    public function curlResolve(): string
    {
        $address = str_contains($this->address, ':') ? "[{$this->address}]" : $this->address;

        return "{$this->host}:{$this->port}:{$address}";
    }
}

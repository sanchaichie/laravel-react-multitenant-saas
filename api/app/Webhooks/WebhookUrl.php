<?php

namespace App\Webhooks;

use App\Webhooks\Contracts\HostResolver;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which URLs the server may POST webhooks to. The server calls a URL a user typed,
 * so without this it could be pointed at itself, the private network or the cloud
 * metadata address (SSRF). Checked when an endpoint is saved and again before every
 * delivery, which then connects only to the address checked here (WebhookTarget).
 *
 * Allowed: https, no credentials in the URL, a host whose every address is public.
 * config('webhooks.allowed_private_targets') lists exact host:port pairs that skip
 * the address check and may use http (a local receiver, never in production).
 */
class WebhookUrl
{
    /** Private, shared, loopback, link-local (the cloud metadata address), documentation, multicast, reserved. */
    private const NOT_PUBLIC_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /**
     * IPv6 must be global unicast (2000::/3) and none of these: documentation, Teredo
     * and 6to4 (they can wrap a private IPv4 address), and the like. Loopback,
     * IPv4-mapped (::ffff:127.0.0.1), NAT64, unique-local (fc00::/7), link-local and
     * multicast are all outside 2000::/3.
     */
    private const NOT_PUBLIC_V6 = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @throws UnsafeWebhookUrl
     */
    public function target(string $url): WebhookTarget
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($parts === false || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeWebhookUrl('Enter a valid https URL.');
        }

        // Plain ASCII names only (letters, digits, dots, hyphens; no trailing dot), or an
        // IP literal. curl turns an IDN into its xn-- form, or keys a trailing dot apart,
        // and would then look the name up itself instead of using the pinned address.
        if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $host) !== 1) {
            throw new UnsafeWebhookUrl('Use the host\'s plain ASCII name (for an international domain, its xn-- form).');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrl('The URL can\'t contain a username or password.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $privateAllowed = in_array("{$host}:{$port}", config('webhooks.allowed_private_targets', []), true);

        if ($scheme !== 'https' && ! $privateAllowed) {
            throw new UnsafeWebhookUrl('The URL must use https.');
        }

        $addresses = $this->resolver->resolve($host);

        // Every address, not just the first: the connection could use any of them. A host
        // that can't be found gets the same answer, so this can't map internal DNS names.
        $public = $addresses !== [] && ($privateAllowed || array_all($addresses, fn (string $address) => self::isPublic($address)));

        if (! $public) {
            throw new UnsafeWebhookUrl('The URL must point to a public address.');
        }

        return new WebhookTarget($url, $scheme, $host, $port, $addresses[0]);
    }

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! IpUtils::checkIp($address, self::NOT_PUBLIC_V4);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return IpUtils::checkIp($address, '2000::/3') && ! IpUtils::checkIp($address, self::NOT_PUBLIC_V6);
        }

        return false;
    }
}

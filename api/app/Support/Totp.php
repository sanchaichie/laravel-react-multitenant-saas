<?php

namespace App\Support;

/**
 * Time-based one-time passwords (RFC 6238), as authenticator apps make them:
 * HMAC-SHA1, 30 second steps, 6 digits, base32 secret.
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Steps either side of now that are still accepted, for clocks that are a little off. */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new random secret (160 bits, as RFC 4226 recommends), base32 encoded.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The step of a valid code, or null. A step at or before $lastUsedStep is
     * refused, so a code can't be used twice.
     */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $time = null): ?int
    {
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $current = self::step($time ?? now()->getTimestamp());

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }

            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    public static function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);

        // Dynamic truncation (RFC 4226 section 5.3).
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The otpauth:// link an authenticator app reads from the QR code.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim(str_replace(' ', '', $encoded), '='));

        $bits = '';
        foreach (str_split($encoded) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Not a base32 string.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}

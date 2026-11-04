<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * One-time codes for logging in without the authenticator app. Only their sha256
 * is stored: they're random, so a slow hash isn't needed.
 */
class RecoveryCodes
{
    public const COUNT = 8;

    /**
     * @return list<string> e.g. "k3f9q-x7m2p"
     */
    public static function generate(): array
    {
        return array_map(
            fn () => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, self::COUNT),
        );
    }

    public static function hash(string $code): string
    {
        return hash('sha256', Str::lower(trim($code)));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function hashAll(array $codes): array
    {
        return array_map(self::hash(...), $codes);
    }
}

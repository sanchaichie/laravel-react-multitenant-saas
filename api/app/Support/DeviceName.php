<?php

namespace App\Support;

/**
 * A readable name for a User-Agent, such as "Chrome on macOS", for the list of
 * signed-in devices. Rough on purpose: it's a label, never used for security.
 */
class DeviceName
{
    /** Checked in order: Edge and Opera also say "Chrome", Chrome also says "Safari". */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'FxiOS/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /** iOS and Android before macOS and Linux, which their User-Agents also mention. */
    private const SYSTEMS = [
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $browser = self::firstMatch(self::BROWSERS, $userAgent);
        $system = self::firstMatch(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => $system,
            default => 'Unknown device',
        };
    }

    /**
     * @param  array<string, string>  $needles
     */
    private static function firstMatch(array $needles, string $userAgent): ?string
    {
        foreach ($needles as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}

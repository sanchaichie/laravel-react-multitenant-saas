<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class IssueTwoFactorChallenge
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    /**
     * The password was right and two-factor is on: instead of a token, a random
     * challenge that CompleteTwoFactorChallenge swaps for one with a code. Only
     * its hash is cached, for 5 minutes, with the device name for the token.
     */
    public function handle(User $user, ?string $deviceName = null): string
    {
        $challenge = Str::random(40);

        Cache::put(self::key($challenge), [
            'user_id' => $user->getKey(),
            'device_name' => $deviceName,
            'fingerprint' => self::fingerprint($user),
        ], self::TTL_SECONDS);
        Cache::put(self::attemptsKey($challenge), 0, self::TTL_SECONDS);

        return $challenge;
    }

    /**
     * Changes when the password or two-factor setup does, so a challenge made
     * before a reset (or a new setup) stops working.
     */
    public static function fingerprint(User $user): string
    {
        return hash('sha256', $user->password.'|'.$user->two_factor_confirmed_at?->toIso8601String());
    }

    public static function key(string $challenge): string
    {
        return 'two-factor-challenge:'.hash('sha256', $challenge);
    }

    public static function attemptsKey(string $challenge): string
    {
        return self::key($challenge).':attempts';
    }
}

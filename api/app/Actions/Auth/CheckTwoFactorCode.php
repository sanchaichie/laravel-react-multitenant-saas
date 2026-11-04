<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\TwoFactorLockedOut;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Checks a code from the authenticator app, or a recovery code, the same way
 * wherever one is asked for (logging in, turning two-factor off, new recovery
 * codes): each code works once, and one account takes at most 10 wrong codes per
 * 15 minutes, across devices and IP addresses.
 */
class CheckTwoFactorCode
{
    public const MAX_FAILURES = 10;

    public const LOCKOUT_SECONDS = 900;

    /**
     * Check and use up a code on a user row the caller has locked (FOR UPDATE), so
     * two requests can't both use the same code.
     *
     * @return int|null how many recovery codes are left, when one was used
     *
     * @throws ValidationException a wrong or reused code (key "code" or "recovery_code")
     */
    public function useCode(User $lockedUser, ?string $code, ?string $recoveryCode): ?int
    {
        if ($recoveryCode !== null) {
            return $this->useRecoveryCode($lockedUser, $recoveryCode);
        }

        $step = Totp::verify((string) $lockedUser->two_factor_secret, (string) $code, $lockedUser->two_factor_last_used_step);
        if ($step === null) {
            throw ValidationException::withMessages([
                'code' => ["That code isn't right. Check the time on your phone and try the newest code."],
            ]);
        }

        $lockedUser->forceFill(['two_factor_last_used_step' => $step])->save();

        return null;
    }

    /**
     * For an account action: lock the user, check the code, then run $then on the
     * locked row in the same transaction. Every try counts toward the account's limit.
     *
     * @template T
     *
     * @param  Closure(User): T  $then
     * @return T
     *
     * @throws ValidationException too many tries, two-factor off, or a wrong code
     */
    public function run(User $user, ?string $code, ?string $recoveryCode, Closure $then): mixed
    {
        $field = $recoveryCode !== null ? 'recovery_code' : 'code';
        $this->countAttempt($user->id, $field);

        try {
            $result = DB::transaction(function () use ($user, $code, $recoveryCode, $then) {
                $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

                if (! $locked->hasTwoFactorEnabled()) {
                    throw ValidationException::withMessages([
                        'two_factor' => ['Two-factor authentication is off.'],
                    ]);
                }

                $this->useCode($locked, $code, $recoveryCode);

                return $then($locked);
            });
        } catch (ValidationException $exception) {
            // Outside the transaction: a database cache store would roll the count back.
            if (array_key_exists($field, $exception->errors())) {
                $this->recordFailure($user->id);
            } else {
                $this->giveBack($user->id);
            }

            throw $exception;
        }

        $this->giveBack($user->id);

        return $result;
    }

    /**
     * Count a try before its code is checked, so requests racing each other can't
     * get past the limit: the ones over it are refused without a check. A right
     * code gives its try back (giveBack) but clears nothing, so someone with the
     * password gets 10 wrong guesses per 15 minutes however often the owner logs in.
     *
     * @throws ValidationException under $field, while the account is paused
     */
    public function countAttempt(int $userId, string $field): void
    {
        $key = self::limiterKey($userId);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)
            || RateLimiter::hit($key, self::LOCKOUT_SECONDS) > self::MAX_FAILURES) {
            throw ValidationException::withMessages([
                $field => ['Too many wrong codes for this account. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.'],
            ]);
        }
    }

    /**
     * A wrong code (already counted). The first time the limit is reached the
     * account is told: whoever typed it got past the password.
     */
    public function giveBack(int $userId): void
    {
        $key = self::limiterKey($userId);

        // Not below zero: if the window ran out meanwhile, there's nothing to give back.
        if (RateLimiter::attempts($key) > 0) {
            RateLimiter::decrement($key, self::LOCKOUT_SECONDS);
        }
    }

    public function recordFailure(int $userId): void
    {
        if (RateLimiter::attempts(self::limiterKey($userId)) === self::MAX_FAILURES) {
            User::find($userId)?->notify(new TwoFactorLockedOut);
        }
    }

    public static function limiterKey(int $userId): string
    {
        return 'two-factor:user:'.$userId;
    }

    /**
     * @return int how many are left
     */
    private function useRecoveryCode(User $user, string $code): int
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $hash = RecoveryCodes::hash($code);

        // No early exit, so the time taken doesn't tell which code matched.
        $index = null;
        foreach ($hashes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                $index = $i;
            }
        }

        if ($index === null) {
            throw ValidationException::withMessages([
                'recovery_code' => ["That recovery code isn't right, or it was already used."],
            ]);
        }

        unset($hashes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

        return count($hashes);
    }
}

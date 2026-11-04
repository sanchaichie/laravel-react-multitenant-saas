<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\RecoveryCodeUsed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteTwoFactorChallenge
{
    public function __construct(
        private readonly IssueToken $issueToken,
        private readonly CheckTwoFactorCode $checkCode,
    ) {}

    /**
     * Swap a login challenge (IssueTwoFactorChallenge) and a code from the app, or a
     * recovery code, for a token. A challenge allows 5 tries and works once; it stops
     * working when the password or the two-factor setup changes. A code from the app
     * marks the token as having passed one (admin routes); a recovery code doesn't,
     * since new ones can be made with the password alone.
     *
     * @param  array{challenge: string, code?: string|null, recovery_code?: string|null}  $data
     * @return array{user: User, token: string}
     *
     * @throws ValidationException an expired challenge (key "challenge") or a wrong code
     */
    public function handle(array $data): array
    {
        $challenge = $data['challenge'];
        $pending = Cache::get(IssueTwoFactorChallenge::key($challenge));
        $userId = is_array($pending) ? (int) $pending['user_id'] : null;
        // add() first: a key forgotten by a racing request mustn't come back without its TTL.
        if ($userId !== null) {
            Cache::add(IssueTwoFactorChallenge::attemptsKey($challenge), 0, IssueTwoFactorChallenge::TTL_SECONDS);
        }
        $attempts = $userId === null ? false : Cache::increment(IssueTwoFactorChallenge::attemptsKey($challenge));

        if ($userId === null || $attempts === false || $attempts > IssueTwoFactorChallenge::MAX_ATTEMPTS) {
            $this->expire($challenge);
        }

        $recoveryCode = $data['recovery_code'] ?? null;

        // Under "challenge": this login is dropped, and the app goes back to the password.
        try {
            $this->checkCode->countAttempt($userId, 'challenge');
        } catch (ValidationException $exception) {
            $this->forget($challenge);

            throw $exception;
        }

        try {
            [$user, $remaining] = DB::transaction(function () use ($challenge, $pending, $userId, $data, $recoveryCode) {
                // Locked, so two requests can't both use the same code or the same challenge.
                $user = User::query()->lockForUpdate()->find($userId);

                if ($user === null || ! $user->hasTwoFactorEnabled()
                    || ! hash_equals($pending['fingerprint'] ?? '', IssueTwoFactorChallenge::fingerprint($user))
                    || ! Cache::has(IssueTwoFactorChallenge::key($challenge))) {
                    $this->expire($challenge);
                }

                // Suspended after the password step.
                if ($user->isSuspended()) {
                    $this->expire($challenge, User::SUSPENDED_MESSAGE);
                }

                $remaining = $this->checkCode->useCode($user, $data['code'] ?? null, $recoveryCode);

                // Used up while the row is still locked: a second request waits, then finds it gone.
                $this->forget($challenge);

                return [$user, $remaining];
            });
        } catch (ValidationException $exception) {
            if (array_key_exists('challenge', $exception->errors())) {
                // Again: with the database cache store, the rollback undid the forget.
                $this->forget($challenge);
                // Not a wrong code: the try doesn't count.
                $this->checkCode->giveBack($userId);
            } else {
                $this->checkCode->recordFailure($userId);

                if ($attempts >= IssueTwoFactorChallenge::MAX_ATTEMPTS) {
                    $this->expire($challenge, "That code isn't right, and this login has expired after 5 tries. Enter your password again.");
                }
            }

            throw $exception;
        }

        $this->checkCode->giveBack($userId);

        if ($remaining !== null) {
            $user->notify(new RecoveryCodeUsed($remaining));
        }

        return ['user' => $user, 'token' => $this->issueToken->handle($user, $pending['device_name'] ?? null, twoFactor: $recoveryCode === null)];
    }

    /**
     * @throws ValidationException always
     */
    private function expire(string $challenge, string $message = 'This login has expired. Enter your password again.'): never
    {
        $this->forget($challenge);

        throw ValidationException::withMessages(['challenge' => [$message]]);
    }

    private function forget(string $challenge): void
    {
        Cache::forget(IssueTwoFactorChallenge::key($challenge));
        Cache::forget(IssueTwoFactorChallenge::attemptsKey($challenge));
    }
}

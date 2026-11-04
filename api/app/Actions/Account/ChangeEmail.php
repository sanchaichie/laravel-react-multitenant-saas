<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ChangeEmail
{
    public const PER_DAY = 10;

    public const PER_TARGET_PER_DAY = 3;

    /**
     * Ask to change the email (the request checked the current password). Nothing
     * changes until the link sent to the new address is followed (ConfirmEmailChange):
     * the account keeps its email, so its owner can still log in and reset the password
     * with it. A newer request replaces an older one.
     */
    public function handle(User $user, string $email): User
    {
        if ($email === $user->email) {
            throw ValidationException::withMessages(['email' => ["That's already your email."]]);
        }

        // 10 requests a day, counting only ones that send mail (typos and wrong
        // passwords are limited by the route's throttles instead).
        $dailyKey = 'account-email:changes:'.$user->id;
        if (RateLimiter::tooManyAttempts($dailyKey, self::PER_DAY)) {
            throw new ThrottleRequestsException('You can ask to change your email 10 times a day. Try again tomorrow.');
        }

        $user->forceFill(['pending_email' => $email])->save();
        RateLimiter::hit($dailyKey, 86400);

        // At most 3 confirmation emails a day to one address, whoever asks: throwaway
        // accounts can't flood someone's inbox. Answered the same either way, so this
        // doesn't tell whether an address is being targeted.
        $targetKey = 'account-email:to:'.sha1($email);
        if (! RateLimiter::tooManyAttempts($targetKey, self::PER_TARGET_PER_DAY)) {
            RateLimiter::hit($targetKey, 86400);
            Notification::route('mail', $email)->notify(new ConfirmEmailChange($user->id, $email));
        }
        $user->notify(new EmailChangeRequested($email));

        return $user;
    }
}

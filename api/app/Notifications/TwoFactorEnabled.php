<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent when two-factor authentication is turned on, so it can't happen unnoticed,
 * with a link (7 days) that turns it off again (RevertTwoFactor): someone who had
 * the password and set it up with their own phone can't keep the owner out.
 */
class TwoFactorEnabled extends Notification implements ShouldQueue
{
    use Queueable;

    public const UNDO_DAYS = 7;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  int  $confirmedAt  two_factor_confirmed_at as a timestamp: the link only undoes this setup
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $confirmedAt,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Two-factor authentication was turned on')
            ->line('Two-factor authentication is now on for your '.config('app.name').' account. Logging in will ask for a code from your authenticator app.')
            ->line('Your other devices were signed out.')
            ->line("If you didn't do this, someone else has your password and set it up with their phone. Undo it: two-factor is turned off, every device is signed out, and you get a link to set a new password.")
            ->action("This wasn't me", $this->undoUrl())
            ->line('The link works for '.self::UNDO_DAYS.' days.');
    }

    // The React app's /revert-two-factor, which posts it back. Bound to this exact setup.
    private function undoUrl(): string
    {
        $parameters = ['id' => $this->userId, 'at' => $this->confirmedAt];
        $signed = URL::temporarySignedRoute('account.two-factor.revert', now()->addDays(self::UNDO_DAYS), $parameters, absolute: false);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return config('app.frontend_url').'/revert-two-factor?'.http_build_query([
            ...$parameters,
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}

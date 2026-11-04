<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when two-factor authentication is turned off, so a takeover doesn't go unnoticed.
 */
class TwoFactorDisabled extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

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
            ->subject('Two-factor authentication was turned off')
            ->line('Two-factor authentication is now off for your '.config('app.name').' account. Logging in only needs your password.')
            ->line("If you didn't do this, reset your password right away: someone else may have access to your account.");
    }
}

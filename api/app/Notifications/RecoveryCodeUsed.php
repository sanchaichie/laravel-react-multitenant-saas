<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when someone logs in with a recovery code instead of the authenticator app.
 */
class RecoveryCodeUsed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $remaining) {}

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
            ->subject('A recovery code was used to log in')
            ->line('Someone logged in to your '.config('app.name').' account with a recovery code. You have '.$this->remaining.' left.')
            ->line('You can make a new set on your account page.')
            ->line("If you didn't do this, reset your password right away: someone else may have access to your account.");
    }
}

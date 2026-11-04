<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * New recovery codes were made: sent so it can't happen unnoticed.
 */
class RecoveryCodesRegenerated extends Notification implements ShouldQueue
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
            ->subject('New recovery codes were made')
            ->line('A new set of two-factor recovery codes was made for your '.config('app.name').' account. The old codes no longer work.')
            ->line("If you didn't do this, change your password right away: someone else may have access to your account.");
    }
}

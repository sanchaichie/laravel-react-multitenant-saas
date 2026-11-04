<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Several wrong two-factor codes were entered: sent so it can't happen unnoticed.
 */
class TwoFactorLockedOut extends Notification implements ShouldQueue
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
            ->subject('Several wrong two-factor codes were entered')
            ->line('Someone entered the right password for your '.config('app.name').' account, then several wrong two-factor codes, so logging in is paused for 15 minutes.')
            ->line("If this wasn't you, someone has your password: change it right away.");
    }
}

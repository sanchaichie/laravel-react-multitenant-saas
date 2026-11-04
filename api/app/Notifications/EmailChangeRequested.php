<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the current address when someone asks to change it. Nothing has changed
 * yet, so the owner can still log in (or reset the password) with this address and
 * cancel it. The new address is only shown masked.
 */
class EmailChangeRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $newEmail) {}

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
            ->subject('Someone asked to change your email address')
            ->line('A change of your '.config('app.name').' account\'s email to '.EmailChanged::mask($this->newEmail).' was requested. It only happens once the new address confirms it.')
            ->line("If this wasn't you, someone has your password: reset it with this address (Forgot password on the login page). That cancels the change and signs out every device.");
    }
}

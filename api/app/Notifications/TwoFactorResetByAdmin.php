<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when support turned two-factor off for the account (ResetUserTwoFactor).
 */
class TwoFactorResetByAdmin extends Notification implements ShouldQueue
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
            ->subject('Your two-factor authentication was reset')
            ->line('Support turned off two-factor authentication for your '.config('app.name').' account and signed you out of every device. Log in with your password, then turn two-factor on again in your account settings.')
            ->action('Log in', config('app.frontend_url').'/login')
            ->line("If you didn't ask for this, reset your password right away and contact support.");
    }
}

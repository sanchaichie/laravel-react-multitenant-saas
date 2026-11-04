<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a platform admin opened a read-only "view as user" session on the
 * account (StartImpersonation). Never the admin's reason.
 */
class ViewedBySupport extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly CarbonImmutable $startedAt,
        public readonly int $minutes,
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
            ->subject('Support viewed your account')
            ->line('Support opened your '.config('app.name').' account read-only on '.$this->startedAt->utc()->toDayDateTimeString().' (UTC), for up to '.$this->minutes.' minutes, to help with a problem. They could see what you see, except task descriptions, summaries and chats, and couldn\'t change anything.')
            ->line("If you didn't contact support, reply to this email. Changing your password ends the session right away.");
    }
}

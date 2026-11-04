<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when support made the user an Owner of an organization (MakeMemberOwner).
 * The name is user input: Markdown::withSecuredEncoding (AppServiceProvider) keeps
 * it from adding links or formatting.
 */
class MadeOwnerByAdmin extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $organizationName) {}

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
            ->subject('You are now an Owner of '.$this->organizationName)
            ->line('Support made you an Owner of '.$this->organizationName.' on '.config('app.name').'. Owners have every permission, including members, roles and billing.')
            ->action('Open '.config('app.name'), config('app.frontend_url'));
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the new address of a requested email change: the link (to the React app's
 * /confirm-email, which posts it back) makes it the account's email. Takes plain
 * values: it goes to an address that isn't the account's yet.
 */
class ConfirmEmailChange extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $userId, public readonly string $newEmail) {}

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
            ->subject('Confirm your new email address')
            ->line('Someone asked to use this address for their '.config('app.name').' account.')
            ->action('Confirm email address', $this->confirmationUrl())
            ->line("The link works for 60 minutes, while you're logged in to that account. If you didn't ask for this, ignore this email: nothing changes.");
    }

    private function confirmationUrl(): string
    {
        $parameters = ['id' => $this->userId, 'hash' => sha1($this->newEmail)];
        $signed = URL::temporarySignedRoute(
            'account.email.confirm',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            $parameters,
            absolute: false,
        );
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return config('app.frontend_url').'/confirm-email?'.http_build_query([
            ...$parameters,
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}

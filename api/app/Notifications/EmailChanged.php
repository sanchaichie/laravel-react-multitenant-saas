<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent to the old address once an email change is confirmed, with a link (7 days)
 * that undoes it (RevertEmailChange): someone who had the password can't keep the
 * account. The new address is only shown masked.
 */
class EmailChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public const UNDO_DAYS = 7;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $userId,
        public readonly string $oldEmail,
        public readonly string $newEmail,
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
            ->subject('Your email address was changed')
            ->line('The email address of your '.config('app.name').' account was changed to '.self::mask($this->newEmail).'.')
            ->line("If you didn't do this, someone else has your password. Undo it: this address comes back, every device is signed out, and you get a link to set a new password.")
            ->action('Undo the change', $this->undoUrl())
            ->line('The link works for '.self::UNDO_DAYS.' days.');
    }

    /**
     * "a***@example.com": enough to recognize your own address, not to learn someone else's.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    // The React app's /revert-email, which posts it back. Bound to this exact change.
    private function undoUrl(): string
    {
        $parameters = ['id' => $this->userId, 'old' => sha1($this->oldEmail), 'new' => sha1($this->newEmail)];
        $signed = URL::temporarySignedRoute('account.email.revert', now()->addDays(self::UNDO_DAYS), $parameters, absolute: false);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return config('app.frontend_url').'/revert-email?'.http_build_query([
            ...$parameters,
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}

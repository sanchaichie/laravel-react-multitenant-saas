<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * The email verification link, pointing at the React app's /verify-email page,
 * which posts it back to the API (route verification.verify). Signed relative to
 * the API, so the check doesn't depend on the host the API is reached through.
 */
class VerifyEmail extends BaseVerifyEmail implements ShouldQueue
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
     * @param  User  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        $parameters = ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())];

        $signed = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            $parameters,
            absolute: false,
        );

        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return config('app.frontend_url').'/verify-email?'.http_build_query([
            ...$parameters,
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]);
    }
}

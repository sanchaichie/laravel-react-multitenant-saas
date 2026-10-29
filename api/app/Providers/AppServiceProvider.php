<?php

namespace App\Providers;

use App\Support\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: fresh per request and per queued job.
        $this->app->scoped(CurrentOrganization::class);
    }

    /**
     * The destructive migrate commands (fresh, refresh, reset, rollback) and db:wipe
     * refuse to run unless the default connection is a test database (its name ends
     * in "_test", or "_test_N" for parallel runs) or DB_ALLOW_DESTRUCTIVE_COMMANDS is
     * set for that one run. Anything else is someone's data: `--env=testing` without
     * an .env.testing silently uses .env. The name is the one the connection really
     * uses: a DB_URL wins over DB_DATABASE.
     */
    public static function guardDestructiveDatabaseCommands(): void
    {
        $config = (array) config('database.connections.'.config('database.default'));
        $database = (string) ((new ConfigurationUrlParser)->parseConfiguration($config)['database'] ?? '');
        $isTestDatabase = preg_match('/_test(_\d+)?$/', $database) === 1;

        DB::prohibitDestructiveCommands(! $isTestDatabase && ! config('database.allow_destructive_commands'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        self::guardDestructiveDatabaseCommands();

        // Per address and IP, and per address alone, so many IPs can't each take
        // their 6 guesses at one account.
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(6)->by('login:'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('login:'.$email),
            ];
        });

        // Mail goes to addresses users type in: cap how much one account can send, so
        // the app can't be used to flood an inbox.
        RateLimiter::for('verify-email', fn (Request $request) => [
            Limit::perMinute(6)->by('verify-email:'.$request->user()?->id),
            Limit::perHour(10)->by('verify-email:hour:'.$request->user()?->id),
        ]);
        RateLimiter::for('invitations', function (Request $request) {
            $organizationId = app(CurrentOrganization::class)->id();

            return [
                Limit::perMinute(20)->by('invitations:'.$request->user()?->id),
                Limit::perDay(200)->by('invitations:org:'.$organizationId),
                // Re-inviting sends the email again: not over and over to one address.
                Limit::perHour(3)->by('invitations:to:'.$organizationId.'|'.mb_strtolower(trim((string) $request->input('email')))),
            ];
        });

        // {{ }} in Markdown mail escapes HTML but not Markdown: without this, a name
        // like "[Confirm your account](https://evil.example)" becomes a real link in
        // an invitation sent from our address.
        Markdown::withSecuredEncoding();
    }
}

<?php

namespace App\Providers;

use App\Ai\AiBudget;
use App\Ai\Clients\AnthropicClient;
use App\Ai\Clients\BudgetedAiClient;
use App\Ai\Clients\FakeAiClient;
use App\Ai\Clients\RecordingAiClient;
use App\Ai\Contracts\AiClient;
use App\Billing\Contracts\BillingGateway;
use App\Billing\FakeBillingGateway;
use App\Billing\StripeBillingGateway;
use App\Enums\Permission;
use App\Jobs\OrganizationJob;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Policies\BillingPolicy;
use App\Support\CurrentOrganization;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use Laravel\Sanctum\Sanctum;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: fresh per request and per queued job.
        $this->app->scoped(CurrentOrganization::class);

        // The provider from config/ai.php, wrapped so every call lands in ai_usage, and
        // that in the budget check, so a refused call reaches neither.
        $this->app->bind(AiClient::class, fn (Application $app) => new BudgetedAiClient(
            new RecordingAiClient($app->make('ai.provider'), config('ai.model'), config('ai.pricing')),
            $app->make(AiBudget::class),
            $app->make(CurrentOrganization::class),
        ));

        $this->app->bind('ai.provider', fn (Application $app) => match (config('ai.provider')) {
            'anthropic' => new AnthropicClient(
                $app->make(HttpFactory::class),
                config('ai.providers.anthropic'),
                config('ai.model'),
                config('ai.timeout'),
                config('ai.max_tokens'),
                config('ai.fallbacks'),
                config('ai.fallback_models'),
                config('ai.stream_timeout'),
            ),
            // Tests bind their own instance (TestCase) to push responses and inspect requests.
            'fake' => $app->bound(FakeAiClient::class)
                ? $app->make(FakeAiClient::class)
                : new FakeAiClient(config('ai.model'), config('ai.fake_stream_delay_ms')),
            default => throw new InvalidArgumentException('Unknown AI provider ['.config('ai.provider').'].'),
        });

        // Payments, from config/billing.php. Tests bind their own fake (TestCase).
        $this->app->bind(BillingGateway::class, fn (Application $app) => match (config('billing.provider')) {
            'stripe' => $app->make(StripeBillingGateway::class),
            'fake' => $app->bound(FakeBillingGateway::class) ? $app->make(FakeBillingGateway::class) : new FakeBillingGateway,
            default => throw new InvalidArgumentException('Unknown billing provider ['.config('billing.provider').'].'),
        });

        // The organization is the Stripe customer. Cashier's own routes are off: ours is
        // POST /api/stripe/webhook, which always checks the signature (VerifyStripeWebhook).
        Cashier::useCustomerModel(Organization::class);
        Cashier::ignoreRoutes();
        // A failed renewal keeps the plan while Stripe retries the card; Stripe then
        // cancels the subscription (Billing settings), which ends the plan.
        Cashier::keepPastDueSubscriptionsActive();
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

        // The fake gateway never charges anyone, and with fake_complete_checkout it hands
        // out Pro for free: only on a developer's machine and in tests, never on a server
        // others can reach (production or a staging copy).
        if (config('billing.provider') === 'fake' && ! $this->app->environment('local', 'testing')) {
            throw new LogicException('BILLING_PROVIDER=fake is only allowed when APP_ENV is local or testing.');
        }

        // Billing has no model of its own: always the current organization's.
        Gate::define('manageBilling', [BillingPolicy::class, 'manage']);

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
        // Every route that checks your current password (email, password, delete account)
        // shares one counter, so a stolen token can't spread guesses across them.
        RateLimiter::for('current-password', fn (Request $request) => [
            Limit::perMinute(5)->by('current-password:'.$request->user()?->id),
            Limit::perHour(20)->by('current-password:hour:'.$request->user()?->id),
        ]);
        // Changing your email mails the new address: per minute here; ChangeEmail also
        // allows 10 actual changes a day.
        RateLimiter::for('account-email', fn (Request $request) => Limit::perMinute(6)->by('account-email:'.$request->user()?->id));
        RateLimiter::for('invitations', function (Request $request) {
            $organizationId = app(CurrentOrganization::class)->id();

            return [
                Limit::perMinute(20)->by('invitations:'.$request->user()?->id),
                Limit::perDay(200)->by('invitations:org:'.$organizationId),
                // Re-inviting sends the email again: not over and over to one address.
                Limit::perHour(3)->by('invitations:to:'.$organizationId.'|'.mb_strtolower(trim((string) $request->input('email')))),
            ];
        });

        // AI per organization: requests from the app (throttle:ai) and the provider
        // calls of AI jobs (RateLimited('ai')) are counted apart, so one click isn't
        // counted twice. Automatic summaries have their own key (QueueTaskSummary).
        RateLimiter::for('ai', function (object $subject) {
            $limit = Limit::perMinute(config('ai.rate_limit_per_minute'));

            if ($subject instanceof OrganizationJob) {
                return $limit->by('job:'.$subject->organizationId);
            }

            // The throttle runs before the organization middleware: the organization is
            // resolved from the user here. Without one, count per user (the 403 follows).
            $organizationId = app(CurrentOrganization::class)->id();

            return $limit->by($organizationId !== null ? "request:{$organizationId}" : 'request:user:'.$subject->user()?->id)
                ->response(fn () => response()->json([
                    'message' => 'Too many AI requests for this organization. Try again in a minute.',
                ], 429));
        });

        // Billing routes that call Stripe. Stripe's rate limit is for the whole account, so
        // one busy organization mustn't use it up: per user, and per organization. Only
        // people who may manage billing count toward the organization's limit: the throttle
        // runs before the policy, so others' 403s could otherwise lock its owners out.
        RateLimiter::for('stripe', function (Request $request) {
            $organization = app(CurrentOrganization::class);
            $limits = [
                Limit::perMinute(20)->by('stripe:user:'.$request->user()?->id)->response(fn () => response()->json([
                    'message' => 'Too many billing requests. Try again in a minute.',
                ], 429)),
            ];

            if ($organization->id() !== null && $organization->can(Permission::BillingManage)) {
                $limits[] = Limit::perMinute(60)->by('stripe:org:'.$organization->id())->response(fn () => response()->json([
                    'message' => 'Too many billing requests for this organization. Try again in a minute.',
                ], 429));
            }

            return $limits;
        });

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // 401, like a revoked token: a suspended user's token (they're deleted on
        // suspension; this also covers one issued in a race), and a "view as user"
        // session whose admin could no longer start one (not an admin, two-factor off,
        // suspended) or whose user became an admin. Sanctum checks expires_at itself.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid || ! $token->tokenable instanceof User || $token->tokenable->isSuspended()) {
                return false;
            }

            if (! $token->isImpersonation()) {
                return true;
            }

            $admin = $token->impersonator;

            return $admin !== null
                && $admin->isPlatformAdmin()
                && $admin->hasTwoFactorEnabled()
                && ! $admin->isSuspended()
                && ! $token->tokenable->isPlatformAdmin();
        });

        // Platform admin routes, per admin. The throttle runs before EnsurePlatformAdmin, so
        // non-admins aren't counted: a 429 would tell them the area exists (they get 404).
        RateLimiter::for('admin', function (Request $request) {
            $user = $request->user();

            return $user instanceof User && $user->isPlatformAdmin()
                ? Limit::perMinute(60)->by('admin:'.$user->id)
                : Limit::none();
        });

        // {{ }} in Markdown mail escapes HTML but not Markdown: without this, a name
        // like "[Confirm your account](https://evil.example)" becomes a real link in
        // an invitation sent from our address.
        Markdown::withSecuredEncoding();
    }
}

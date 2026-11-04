<?php

use App\Billing\Invoice;
use App\Enums\AuditAction;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\TaskSummaryStatus;
use App\Models\AiCreditPurchase;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskChatMessage;
use App\Models\TaskSummary;
use App\Models\User;
use App\Support\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * A user who is a member of a new organization (with the given role) and has it as current.
 * The organization has its default roles; a Role must be one of its own.
 *
 * @return array{0: User, 1: Organization}
 */
function userInOrganization(DefaultRole $role = DefaultRole::Owner, string $name = 'Acme'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user, $role)->create(['name' => $name]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return [$user, $organization];
}

/**
 * Add a new user to the organization with the given role (it becomes their current one).
 *
 * @param  array<string, mixed>  $attributes
 */
function addMember(Organization $organization, DefaultRole|Role $role = DefaultRole::Member, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $organization->members()->attach($user, ['role_id' => $organization->role($role)->id]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return $user;
}

/**
 * An invitation written directly (no email). Returns it and its plain token.
 *
 * @param  array<string, mixed>  $attributes  any column, e.g. expires_at or accepted_at
 * @return array{0: OrganizationInvitation, 1: string}
 */
function createInvitation(Organization $organization, string $email, DefaultRole|Role $role = DefaultRole::Member, array $attributes = []): array
{
    $token = Str::random(48);

    $roleId = $organization->role($role)->id;

    $invitation = inOrganization($organization, function () use ($email, $roleId, $token, $attributes) {
        $invitation = (new OrganizationInvitation)->forceFill(array_merge([
            'email' => $email,
            'role_id' => $roleId,
            'token_hash' => OrganizationInvitation::hashToken($token),
            'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
        ], $attributes));
        $invitation->save();

        return $invitation;
    });

    return [$invitation, $token];
}

/**
 * Run code acting in an organization (no logged-in user needed), e.g. to create
 * tenant-owned rows. Returns what the callback returns.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inOrganization(Organization $organization, Closure $callback): mixed
{
    return app(CurrentOrganization::class)->run($organization, $callback);
}

/**
 * A custom role in the organization (created as its owner would, without the API).
 *
 * @param  list<Permission>  $permissions
 */
function createRole(Organization $organization, string $name, array $permissions = []): Role
{
    return inOrganization($organization, fn () => Role::factory()->create(['name' => $name, 'permissions' => $permissions]));
}

/**
 * The id of a default role in the organization (by default, the logged-in user's
 * current one), to send as role_id.
 */
function roleId(DefaultRole $role, ?Organization $organization = null): int
{
    $organization ??= Organization::query()->findOrFail(auth()->user()->current_organization_id);

    return $organization->role($role)->id;
}

/**
 * Audit log entries of an organization (any, by default), oldest first, optionally
 * only one action.
 *
 * @return EloquentCollection<int, AuditLog>
 */
function auditEntries(?Organization $organization = null, ?AuditAction $action = null): EloquentCollection
{
    return AuditLog::withoutGlobalScopes()
        ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
        ->when($action, fn ($query) => $query->where('action', $action))
        ->orderBy('id')
        ->get();
}

/**
 * A task summary written directly (no AI call), e.g. a draft to review.
 *
 * @param  array<string, mixed>  $attributes  any column
 */
function createSummary(Organization $organization, Task $task, TaskSummaryStatus $status, array $attributes = []): TaskSummary
{
    return inOrganization($organization, function () use ($task, $status, $attributes) {
        $summary = (new TaskSummary)->forceFill(array_merge([
            'task_id' => $task->id,
            'status' => $status,
            'summary' => $status === TaskSummaryStatus::Pending ? null : 'A summary.',
            'key_points' => $status === TaskSummaryStatus::Pending ? null : ['A point'],
        ], $attributes));
        $summary->save();

        return $summary;
    });
}

/**
 * An ai_usage row (an AI call) of the organization, as RecordingAiClient writes it.
 *
 * @param  array<string, mixed>  $attributes
 */
function recordAiUsage(Organization $organization, float $costUsd, array $attributes = []): AiUsage
{
    return inOrganization($organization, function () use ($costUsd, $attributes) {
        $usage = (new AiUsage)->forceFill(array_merge([
            'feature' => 'task_summary',
            'model' => 'claude-haiku-5-5',
            'input_tokens' => 1000,
            'output_tokens' => 200,
            'cost_usd' => $costUsd,
            'status' => 'succeeded',
        ], $attributes));
        $usage->save();

        return $usage;
    });
}

/**
 * The server-sent events in a streamed response, in order.
 *
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function sseEvents(TestResponse $response): array
{
    $events = [];

    foreach (array_filter(explode("\n\n", $response->streamedContent())) as $block) {
        preg_match('/^event: (.+)\ndata: (.+)$/s', $block, $match);
        $events[] = ['event' => $match[1], 'data' => json_decode($match[2], true)];
    }

    return $events;
}

/**
 * A chat message written directly (no AI call).
 *
 * @param  array<string, mixed>  $attributes
 */
function createChatMessage(Organization $organization, Task $task, User $user, ChatMessageRole $role, string $content, array $attributes = []): TaskChatMessage
{
    return inOrganization($organization, function () use ($task, $user, $role, $content, $attributes) {
        $message = new TaskChatMessage;
        $message->forceFill(array_merge([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'role' => $role,
            'content' => $content,
            'status' => ChatMessageStatus::Complete,
        ], $attributes))->save();

        return $message;
    });
}

/**
 * Subscribe the organization to Pro directly (as the webhook would), making it a
 * Stripe customer first if needed.
 *
 * @param  array<string, mixed>  $attributes  e.g. ['stripe_status' => 'past_due']
 */
function subscribe(Organization $organization, array $attributes = []): Subscription
{
    if (! $organization->hasStripeId()) {
        $organization->forceFill(['stripe_id' => 'cus_'.Str::random(14)])->save();
    }

    $subscription = $organization->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(14),
        'stripe_status' => 'active',
        'stripe_price' => config('billing.plans.pro.price'),
        'quantity' => 1,
    ], $attributes));
    $organization->unsetRelation('subscriptions');

    return $subscription;
}

/**
 * Credits the organization bought (as GrantAiCredits would write them).
 *
 * @param  array<string, mixed>  $attributes
 */
function buyCredits(Organization $organization, float $usd = 10, array $attributes = []): AiCreditPurchase
{
    return inOrganization($organization, function () use ($usd, $attributes) {
        $purchase = new AiCreditPurchase;
        $purchase->forceFill(array_merge([
            'pack' => 'credits_10',
            'amount_usd' => $usd,
            'stripe_checkout_session_id' => 'cs_'.Str::random(14),
        ], $attributes))->save();

        return $purchase;
    });
}

/**
 * Post a Stripe event to the webhook, signed like Stripe does (t=..., v1=HMAC-SHA256
 * of "t.payload" with the webhook secret). Pass $secret to sign with a wrong one.
 *
 * @param  array<string, mixed>  $object  the event's data.object
 */
function stripeWebhook(string $type, array $object, ?string $secret = null): TestResponse
{
    $payload = json_encode([
        'id' => 'evt_'.Str::random(14),
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? (string) config('cashier.webhook.secret'));

    // From Stripe, so nobody is logged in: set the test's actingAs user aside for it.
    $user = auth()->guard()->user();
    $guard = config('auth.defaults.guard');
    auth()->forgetGuards();
    config(['auth.defaults.guard' => 'web']);

    try {
        return test()->call('POST', '/api/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: $payload);
    } finally {
        config(['auth.defaults.guard' => $guard]);

        if ($user instanceof User) {
            Sanctum::actingAs($user);
        }
    }
}

/**
 * A Stripe subscription object as webhooks send it (one Pro item).
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function stripeSubscription(Organization $organization, array $attributes = []): array
{
    return array_merge([
        'id' => 'sub_test',
        'object' => 'subscription',
        'customer' => $organization->stripe_id,
        'status' => 'active',
        'cancel_at_period_end' => false,
        'cancel_at' => null,
        'canceled_at' => null,
        'trial_end' => null,
        'metadata' => ['type' => 'default'],
        'items' => ['data' => [[
            'id' => 'si_test',
            'quantity' => 1,
            'price' => ['id' => config('billing.plans.pro.price'), 'product' => 'prod_test'],
        ]]],
    ], $attributes);
}

/**
 * A Stripe Checkout Session for a credit pack as webhooks send it: paid, one-time,
 * started in the organization by $user.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function stripeCreditsSession(Organization $organization, ?User $user = null, array $attributes = []): array
{
    return array_merge([
        'id' => 'cs_test_'.Str::random(10),
        'object' => 'checkout.session',
        'customer' => $organization->stripe_id,
        'client_reference_id' => (string) $organization->id,
        'mode' => 'payment',
        'payment_status' => 'paid',
        'status' => 'complete',
        'amount_total' => 1000,
        'metadata' => ['pack' => 'credits_10', 'user_id' => $user === null ? null : (string) $user->id],
    ], $attributes);
}

/**
 * An invoice for FakeBillingGateway::setInvoices(): a paid $19 Pro invoice unless overridden.
 *
 * @param  array<string, mixed>  $attributes  constructor arguments of App\Billing\Invoice
 */
function invoice(string $id, array $attributes = []): Invoice
{
    return new Invoice(...array_merge([
        'id' => $id,
        'number' => 'ACME-0001',
        'status' => 'paid',
        'total' => 1900,
        'currency' => 'usd',
        'createdAt' => CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'),
        'description' => 'Pro',
        'hostedUrl' => 'https://invoice.stripe.com/i/acct_1/test_1',
        'pdfUrl' => 'https://pay.stripe.com/invoice/acct_1/test_1/pdf',
    ], $attributes));
}

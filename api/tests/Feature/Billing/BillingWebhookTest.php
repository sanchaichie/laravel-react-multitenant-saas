<?php

use App\Ai\AiBudget;
use App\Enums\AuditAction;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

afterEach(fn () => ApiRequestor::setHttpClient(null));

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5]);
    [, $this->acme] = userInOrganization();
    $this->acme->forceFill(['stripe_id' => 'cus_acme'])->save();
});

function planChanges($organization)
{
    return auditEntries($organization, AuditAction::PlanChanged)->values();
}

it('refuses a webhook without a valid signature', function () {
    $this->postJson('/api/stripe/webhook', ['type' => 'customer.subscription.created', 'data' => ['object' => stripeSubscription($this->acme)]])
        ->assertForbidden();
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme), secret: 'whsec_wrong')
        ->assertForbidden();

    expect(Subscription::count())->toBe(0);
});

it('refuses every webhook when no signing secret is configured', function () {
    config(['cashier.webhook.secret' => '']);

    // Signed with the empty secret, which Cashier alone would not check at all.
    $response = stripeWebhook('customer.subscription.created', stripeSubscription($this->acme), secret: '')
        ->assertForbidden();

    // Without saying why: that would tell anyone verification is off.
    expect($response->json('message'))->not->toContain('secret');

    expect(Subscription::count())->toBe(0);
});

it('records a new subscription, switches to Pro and audits the change', function () {
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();

    expect($this->acme->subscriptions()->sole())
        ->stripe_id->toBe('sub_test')
        ->stripe_status->toBe('active')
        ->stripe_price->toBe('price_pro_test')
        ->and(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(50.0)
        ->and(planChanges($this->acme))->toHaveCount(1)
        ->and(planChanges($this->acme)[0])
        ->actor_id->toBeNull()
        ->subject_type->toBe('organization')
        ->subject_id->toBe($this->acme->id)
        ->changes->toEqual(['plan' => ['old' => 'Free', 'new' => 'Pro']]);
});

it('handles a retried event once', function () {
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();
    stripeWebhook('customer.subscription.updated', stripeSubscription($this->acme))->assertOk();

    expect($this->acme->subscriptions()->count())->toBe(1)
        ->and(planChanges($this->acme))->toHaveCount(1);
});

it('does not switch to Pro until the subscription is paid', function () {
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme, ['status' => 'incomplete']))->assertOk();

    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(5.0)
        ->and(planChanges($this->acme))->toBeEmpty();

    stripeWebhook('customer.subscription.updated', stripeSubscription($this->acme))->assertOk();

    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(50.0)
        ->and(planChanges($this->acme))->toHaveCount(1);
});

it('keeps Pro while a cancellation is scheduled, ends it on the date and audits it when Stripe deletes it', function () {
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();

    stripeWebhook('customer.subscription.updated', stripeSubscription($this->acme, ['cancel_at' => now()->addDays(10)->timestamp]))->assertOk();

    expect($this->acme->subscriptions()->sole()->ends_at)->not->toBeNull()
        ->and(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(50.0)
        ->and(planChanges($this->acme))->toHaveCount(1);

    // The period ends: Free right away, even before Stripe's event arrives.
    $this->travelTo(now()->addDays(10)->addMinute());
    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(5.0);

    // Stripe deletes it at (or after) the end; that's when it's audited.
    stripeWebhook('customer.subscription.deleted', stripeSubscription($this->acme, ['status' => 'canceled']))->assertOk();

    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(5.0)
        ->and(planChanges($this->acme))->toHaveCount(2)
        ->and(planChanges($this->acme)[1]->changes)->toEqual(['plan' => ['old' => 'Pro', 'new' => 'Free']]);
});

it('handles a cancellation at the period end, as the Customer Portal sends it', function () {
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();

    // Cashier reads the period end from Stripe for this payload: answer that call.
    config(['cashier.secret' => 'sk_test_fake']);
    $periodEnd = now()->addDays(20)->startOfSecond();
    ApiRequestor::setHttpClient(new class($periodEnd->timestamp) implements ClientInterface
    {
        public function __construct(private readonly int $periodEnd) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            expect($absUrl)->toContain('/v1/subscription_items/si_test');

            return [json_encode(['id' => 'si_test', 'object' => 'subscription_item', 'current_period_end' => $this->periodEnd]), 200, []];
        }
    });

    stripeWebhook('customer.subscription.updated', stripeSubscription($this->acme, ['cancel_at_period_end' => true]))->assertOk();

    expect($this->acme->subscriptions()->sole()->ends_at->timestamp)->toBe($periodEnd->timestamp)
        ->and(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(50.0)
        ->and(planChanges($this->acme))->toHaveCount(1);
});

it('ignores a late update for a subscription Stripe already deleted', function () {
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();
    stripeWebhook('customer.subscription.deleted', stripeSubscription($this->acme, ['status' => 'canceled']))->assertOk();

    // A retried "updated" from before the deletion arrives last.
    stripeWebhook('customer.subscription.updated', stripeSubscription($this->acme))->assertOk();

    expect($this->acme->subscriptions()->sole()->stripe_status)->toBe('canceled')
        ->and(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(5.0)
        ->and(planChanges($this->acme))->toHaveCount(2);
});

it('audits the end of the plan when the Stripe customer is deleted', function () {
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();

    stripeWebhook('customer.deleted', ['id' => 'cus_acme', 'object' => 'customer'])->assertOk();

    expect($this->acme->fresh()->stripe_id)->toBeNull()
        ->and(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(5.0)
        ->and(planChanges($this->acme)->last()->changes)->toEqual(['plan' => ['old' => 'Pro', 'new' => 'Free']]);
});

it('warns when an organization ends up paying for two plan subscriptions', function () {
    Log::spy();

    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme))->assertOk();
    stripeWebhook('customer.subscription.created', stripeSubscription($this->acme, [
        'id' => 'sub_second',
        'items' => ['data' => [['id' => 'si_second', 'quantity' => 1, 'price' => ['id' => 'price_pro_test', 'product' => 'prod_test']]]],
    ]))->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'more than one paid plan subscription')
        && collect($context['subscriptions'])->sort()->values()->all() === ['sub_second', 'sub_test']);
    expect(planChanges($this->acme))->toHaveCount(1);
});

it('ignores a subscription event without a customer', function () {
    $object = stripeSubscription($this->acme);
    unset($object['customer']);

    stripeWebhook('customer.subscription.created', $object)->assertOk();
    stripeWebhook('customer.subscription.updated', $object)->assertOk();

    expect(Subscription::count())->toBe(0);
});

it('ignores events for a customer it does not know', function () {
    stripeWebhook('customer.subscription.created', [...stripeSubscription($this->acme), 'customer' => 'cus_unknown'])->assertOk();

    expect(Subscription::count())->toBe(0)
        ->and(planChanges($this->acme))->toBeEmpty();
});

it('answers other events without doing anything', function () {
    stripeWebhook('invoice.created', ['id' => 'in_test', 'customer' => 'cus_acme'])->assertOk();

    expect(Subscription::count())->toBe(0);
});

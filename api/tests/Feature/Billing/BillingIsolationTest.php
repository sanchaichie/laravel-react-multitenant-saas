<?php

use App\Ai\AiBudget;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\AiCreditPurchase;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5]);
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');
    subscribe($this->orgB);
    $this->orgB->forceFill(['pm_type' => 'visa', 'pm_last_four' => '4242'])->save();

    // Org A's owner is the attacker in every test.
    Sanctum::actingAs($this->ownerA);
});

it('shows only its own organization\'s billing, whatever organization the request names', function () {
    $this->getJson("/api/billing?organization_id={$this->orgB->id}")
        ->assertOk()
        ->assertJsonPath('data.plan.key', 'free')
        ->assertJsonPath('data.payment_method', null)
        ->assertJsonPath('data.has_billing_account', false);
});

it('starts a checkout only for its own organization', function () {
    $this->postJson('/api/billing/checkout', ['plan' => 'pro', 'organization_id' => $this->orgB->id])->assertOk();

    expect($this->billing->checkouts())->toBe([
        ['organization_id' => $this->orgA->id, 'user_id' => $this->ownerA->id, 'plan' => 'pro'],
    ]);
});

it('cannot open another organization\'s billing portal', function () {
    $this->postJson('/api/billing/portal', ['organization_id' => $this->orgB->id])->assertUnprocessable();

    expect($this->billing->portals())->toBe([]);
});

it('cannot use billing through a role it has in another organization but not here', function () {
    // Member in A, owner in B: the current organization's role is what counts.
    $member = addMember($this->orgA, DefaultRole::Member);
    $this->orgB->members()->attach($member, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);
    Sanctum::actingAs($member);

    $this->getJson('/api/billing')->assertForbidden();
    $this->postJson('/api/billing/portal')->assertForbidden();
    $this->billing->assertNothingStarted();
});

it('applies a webhook only to the organization that is the Stripe customer', function () {
    $this->orgA->forceFill(['stripe_id' => 'cus_a'])->save();

    stripeWebhook('customer.subscription.deleted', [...stripeSubscription($this->orgA), 'id' => $this->orgB->subscriptions()->value('stripe_id')])
        ->assertOk();

    // B's subscription is untouched: A's customer id doesn't own it.
    expect(app(AiBudget::class)->limit($this->orgB->fresh()))->toBe(50.0)
        ->and(auditEntries($this->orgB, AuditAction::PlanChanged))->toBeEmpty();
});

it('cannot change another organization\'s subscription with an update for its own customer', function (array $attributes) {
    $this->orgA->forceFill(['stripe_id' => 'cus_a'])->save();
    $orgBSubscription = $this->orgB->subscriptions()->sole();

    stripeWebhook('customer.subscription.updated', [...stripeSubscription($this->orgA, $attributes), 'id' => $orgBSubscription->stripe_id]);

    expect($orgBSubscription->fresh())
        ->not->toBeNull()
        ->stripe_status->toBe('active')
        ->stripe_price->toBe('price_pro_test')
        ->and(app(AiBudget::class)->limit($this->orgB->fresh()))->toBe(50.0)
        ->and(auditEntries($this->orgB, AuditAction::PlanChanged))->toBeEmpty();
})->with([
    'incomplete_expired' => [['status' => 'incomplete_expired']],
    'another price' => [['items' => ['data' => [['id' => 'si_other', 'quantity' => 1, 'price' => ['id' => 'price_cheap', 'product' => 'prod_test']]]]]],
]);

it('caps the budget at the current organization\'s plan, not another one the user pays for', function () {
    // Owner of A (Free) and of B (Pro), acting in A.
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 50])
        ->assertUnprocessable()
        ->assertJsonPath('errors.monthly_budget_usd.0', 'The Free plan allows at most $5.00 a month. Upgrade in Billing for more.');
    $this->getJson('/api/ai-usage')->assertOk()->assertJsonPath('data.budget.plan', 'Free');

    expect($this->orgA->fresh()->ai_monthly_budget_usd)->toBeNull();
});

it('records plan changes only in the organization that changed', function () {
    $this->orgA->forceFill(['stripe_id' => 'cus_a'])->save();

    stripeWebhook('customer.subscription.created', stripeSubscription($this->orgA))->assertOk();

    expect(auditEntries($this->orgA, AuditAction::PlanChanged))->toHaveCount(1)
        ->and(auditEntries($this->orgB, AuditAction::PlanChanged))->toBeEmpty()
        ->and(app(AiBudget::class)->limit($this->orgA->fresh()))->toBe(50.0);
});

it('grants credits only to the organization that is the Stripe customer', function () {
    $this->orgA->forceFill(['stripe_id' => 'cus_a'])->save();

    // B's customer, with A's buyer as metadata: B gets them, A's owner isn't recorded.
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->orgB, $this->ownerA))->assertOk();

    expect(app(AiBudget::class)->credits($this->orgA))->toBe(0.0)
        ->and(app(AiBudget::class)->credits($this->orgB))->toBe(10.0)
        ->and(auditEntries($this->orgA, AuditAction::AiCreditsPurchased))->toBeEmpty()
        ->and(auditEntries($this->orgB, AuditAction::AiCreditsPurchased)->sole()->actor_id)->toBeNull();

    // A session for B's customer that says it was started for A grants nothing at all.
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->orgB, $this->ownerB, [
        'client_reference_id' => (string) $this->orgA->id,
    ]))->assertOk();

    expect(app(AiBudget::class)->credits($this->orgA))->toBe(0.0)
        ->and(app(AiBudget::class)->credits($this->orgB))->toBe(10.0);

    $this->getJson('/api/ai-usage')->assertOk()->assertJsonPath('data.budget.credits_usd', 0);
    $this->getJson('/api/billing')->assertOk()->assertJsonPath('data.ai_budget.credits_usd', 0);
});

it('starts a credits checkout only for its own organization', function () {
    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10', 'organization_id' => $this->orgB->id])->assertOk();

    expect($this->billing->creditCheckouts())->toBe([
        ['organization_id' => $this->orgA->id, 'user_id' => $this->ownerA->id, 'pack' => 'credits_10'],
    ]);
});

it('lists only its own organization\'s invoices, whatever organization the request names', function () {
    subscribe($this->orgA);
    $this->billing->setInvoices($this->orgA, [invoice('in_a1')]);
    $this->billing->setInvoices($this->orgB, [invoice('in_b2'), invoice('in_b1')]);

    $this->getJson("/api/billing/invoices?organization_id={$this->orgB->id}")
        ->assertOk()
        ->assertJsonPath('data.*.id', ['in_a1']);

    // Another organization's invoice id as the cursor pages through org A's own invoices.
    $this->getJson('/api/billing/invoices?starting_after=in_b2')
        ->assertOk()
        ->assertJsonPath('data', []);

    expect(array_column($this->billing->invoiceRequests(), 'organization_id'))->toBe([$this->orgA->id, $this->orgA->id]);
});

it('shows no invoices of another organization to one that never paid', function () {
    $this->billing->setInvoices($this->orgB, [invoice('in_b1')]);

    $this->getJson('/api/billing/invoices')->assertOk()->assertJsonPath('data', []);

    expect($this->billing->invoiceRequests())->toBe([]);
});

it('builds the fake provider\'s demo invoices from its own organization only', function () {
    $subscriptionA = subscribe($this->orgA);
    inOrganization($this->orgB, fn () => AiCreditPurchase::query()->forceCreate([
        'pack' => 'credits_10',
        'amount_usd' => 10,
        'stripe_checkout_session_id' => 'cs_test_b',
    ]));

    $this->getJson('/api/billing/invoices')
        ->assertOk()
        ->assertJsonPath('data.*.id', ["in_fake_sub_{$subscriptionA->id}"]);
});

it('ends only its own customer\'s plan when a Stripe customer is deleted', function () {
    $this->orgA->forceFill(['stripe_id' => 'cus_a'])->save();

    stripeWebhook('customer.deleted', ['id' => 'cus_a', 'object' => 'customer'])->assertOk();

    expect($this->orgB->fresh()->stripe_id)->not->toBeNull()
        ->and($this->orgB->subscriptions()->sole()->stripe_status)->toBe('active')
        ->and(auditEntries($this->orgB))->toBeEmpty();
});

it('cannot take over another organization\'s subscription with a created event for its own customer', function () {
    $this->orgA->forceFill(['stripe_id' => 'cus_a'])->save();
    $subscriptionB = $this->orgB->subscriptions()->sole();

    // Stripe ids are unique: the row stays B's, and the failed insert changes nothing.
    stripeWebhook('customer.subscription.created', [...stripeSubscription($this->orgA), 'id' => $subscriptionB->stripe_id]);

    expect($subscriptionB->fresh()->organization_id)->toBe($this->orgB->id)
        ->and(app(AiBudget::class)->limit($this->orgB->fresh()))->toBe(50.0)
        ->and(auditEntries($this->orgA, AuditAction::PlanChanged))->toBeEmpty();
});

it('grants a delayed payment only to the organization that is the Stripe customer', function () {
    $this->orgA->forceFill(['stripe_id' => 'cus_a'])->save();

    stripeWebhook('checkout.session.async_payment_succeeded', stripeCreditsSession($this->orgB, $this->ownerB))->assertOk();

    expect(app(AiBudget::class)->credits($this->orgA))->toBe(0.0)
        ->and(app(AiBudget::class)->credits($this->orgB))->toBe(10.0);
});

it('follows the current organization for a member of both', function () {
    subscribe($this->orgA);
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);
    $this->billing->setInvoices($this->orgA, [invoice('in_a1')]);
    $this->billing->setInvoices($this->orgB, [invoice('in_b1')]);

    $this->getJson('/api/billing/invoices')->assertOk()->assertJsonPath('data.*.id', ['in_a1']);
    // A's card and plan, not B's, before switching.
    $this->getJson('/api/billing')->assertOk()->assertJsonPath('data.payment_method', null);
    $this->postJson('/api/billing/portal')->assertOk();
    expect($this->billing->portals())->toBe([$this->orgA->id]);

    $this->postJson("/api/organizations/{$this->orgB->id}/switch")->assertOk();
    $this->getJson('/api/billing')->assertOk()->assertJsonPath('data.payment_method.last_four', '4242');
    $this->getJson('/api/billing/invoices')->assertOk()->assertJsonPath('data.*.id', ['in_b1']);

    expect(array_column($this->billing->invoiceRequests(), 'organization_id'))->toBe([$this->orgA->id, $this->orgB->id]);
});

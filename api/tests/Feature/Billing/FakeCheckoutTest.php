<?php

use App\Ai\AiBudget;
use App\Enums\AuditAction;
use App\Providers\AppServiceProvider;
use Laravel\Sanctum\Sanctum;

// BILLING_FAKE_COMPLETE_CHECKOUT (e2e runs only): the fake does what the webhook
// would after a payment, through the same actions.

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5, 'billing.fake_complete_checkout' => true]);
    [$this->owner, $this->acme] = userInOrganization(name: 'Acme');
    Sanctum::actingAs($this->owner);
});

it('subscribes the organization to Pro, audited as a plan change from Stripe', function () {
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertOk();

    $this->getJson('/api/billing')
        ->assertJsonPath('data.plan.key', 'pro')
        ->assertJsonPath('data.has_billing_account', true);
    $entry = auditEntries($this->acme, AuditAction::PlanChanged)->sole();
    expect($entry->actor_id)->toBeNull()
        ->and($entry->changes)->toEqual(['plan' => ['old' => 'Free', 'new' => 'Pro']])
        ->and($entry->metadata)->toEqual(['source' => 'stripe']);

    // The second checkout is refused, as with Stripe.
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertUnprocessable();
});

it('grants the credits, with the buyer', function () {
    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])->assertOk();

    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(15.0)
        ->and(auditEntries($this->acme, AuditAction::AiCreditsPurchased)->sole()->actor_id)->toBe($this->owner->id);
    $this->getJson('/api/billing/invoices')->assertOk()->assertJsonCount(1, 'data');
});

it('changes nothing when it is off, as in every other test', function () {
    config(['billing.fake_complete_checkout' => false]);

    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertOk();
    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])->assertOk();

    expect($this->acme->fresh()->subscriptions()->exists())->toBeFalse()
        ->and(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(5.0);
});

it('refuses the fake provider outside local and testing', function (string $env) {
    $this->app['env'] = $env;

    try {
        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(LogicException::class, 'BILLING_PROVIDER=fake is only allowed when APP_ENV is local or testing.');

        config(['billing.provider' => 'stripe']);
        (new AppServiceProvider($this->app))->boot();
    } finally {
        $this->app['env'] = 'testing';
    }
})->with(['production', 'staging']);

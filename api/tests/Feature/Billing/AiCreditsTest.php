<?php

use App\Actions\Billing\GrantAiCredits;
use App\Ai\AiBudget;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\AiCreditPurchase;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5, 'app.frontend_url' => 'http://app.test']);
    [$this->owner, $this->acme] = userInOrganization();
    $this->acme->forceFill(['stripe_id' => 'cus_acme'])->save();
    Sanctum::actingAs($this->owner);
});

/**
 * @return Collection<int, AiCreditPurchase>
 */
function creditsOf(Organization $organization): Collection
{
    return AiCreditPurchase::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->get();
}

it('starts a credits checkout for the current organization and returns its URL', function () {
    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])
        ->assertOk()
        ->assertExactJson(['data' => ['url' => 'http://app.test/billing?checkout=credits']]);

    expect($this->billing->creditCheckouts())->toBe([
        ['organization_id' => $this->acme->id, 'user_id' => $this->owner->id, 'pack' => 'credits_10'],
    ]);
});

it('lets an organization on any plan buy credits', function () {
    subscribe($this->acme);

    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])->assertOk();
});

it('validates the pack', function (array $payload) {
    $this->postJson('/api/billing/credits/checkout', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pack');

    $this->billing->assertNothingStarted();
})->with([
    'missing' => [[]],
    'unknown' => [['pack' => 'credits_1000']],
    'a price id' => [['pack' => 'price_credits_test']],
    'array' => [['pack' => ['credits_10']]],
]);

it('refuses to sell credits while AI is turned off', function () {
    $this->acme->forceFill(['ai_monthly_budget_usd' => 0])->save();

    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pack' => 'AI is turned off']);

    $this->billing->assertNothingStarted();
});

it('refuses a pack whose Stripe price is not configured', function () {
    config(['billing.credit_packs.credits_10.price' => null]);

    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pack' => 'isn\'t available']);
});

it('lets only roles with billing.manage buy credits, checking before validation', function (DefaultRole $role, int $status) {
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])->assertStatus($status);
    $this->postJson('/api/billing/credits/checkout', ['pack' => 'nope'])->assertStatus($status === 200 ? 422 : 403);
})->with([
    'owner' => [DefaultRole::Owner, 200],
    'admin' => [DefaultRole::Admin, 403],
    'member' => [DefaultRole::Member, 403],
]);

it('lists the credit packs on the billing page without price ids', function () {
    $this->getJson('/api/billing')
        ->assertOk()
        ->assertJsonPath('data.credit_packs', [[
            'key' => 'credits_10',
            'name' => '$10 of AI credits',
            'display_price' => '$10',
            'amount_usd' => 10,
            'available' => true,
        ]]);
});

it('grants the pack\'s credits for a paid checkout and audits the purchase', function () {
    $session = stripeCreditsSession($this->acme, $this->owner);

    stripeWebhook('checkout.session.completed', $session)->assertOk();

    expect(creditsOf($this->acme))->toHaveCount(1)
        ->and(creditsOf($this->acme)->first())
        ->pack->toBe('credits_10')
        ->amount_usd->toBe('10.00')
        ->user_id->toBe($this->owner->id)
        ->stripe_checkout_session_id->toBe($session['id'])
        ->and(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(15.0);

    $entry = auditEntries($this->acme, AuditAction::AiCreditsPurchased)->sole();
    expect($entry)
        ->actor_id->toBe($this->owner->id)
        ->subject_type->toBe('organization')
        ->metadata->toEqual(['source' => 'stripe', 'pack' => '$10 of AI credits', 'amount_usd' => 10]);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.budget.credits_usd', 10)
        ->assertJsonPath('data.budget.limit_usd', 15);
});

it('takes the amount from the config, not from the event', function () {
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner, [
        'amount_total' => 100000,
        'metadata' => ['pack' => 'credits_10', 'amount_usd' => '1000'],
    ]))->assertOk();

    expect(creditsOf($this->acme)->sole()->amount_usd)->toBe('10.00');
});

it('grants a session once, however often Stripe sends it', function () {
    $session = stripeCreditsSession($this->acme, $this->owner);

    stripeWebhook('checkout.session.completed', $session)->assertOk();
    stripeWebhook('checkout.session.completed', $session)->assertOk();
    stripeWebhook('checkout.session.async_payment_succeeded', $session)->assertOk();

    expect(creditsOf($this->acme))->toHaveCount(1)
        ->and(auditEntries($this->acme, AuditAction::AiCreditsPurchased))->toHaveCount(1);
});

it('waits for a delayed payment to succeed', function () {
    $session = stripeCreditsSession($this->acme, $this->owner, ['payment_status' => 'unpaid']);

    stripeWebhook('checkout.session.completed', $session)->assertOk();
    expect(creditsOf($this->acme))->toBeEmpty();

    stripeWebhook('checkout.session.async_payment_failed', $session)->assertOk();
    expect(creditsOf($this->acme))->toBeEmpty();

    stripeWebhook('checkout.session.async_payment_succeeded', [...$session, 'payment_status' => 'paid'])->assertOk();
    expect(creditsOf($this->acme))->toHaveCount(1);
});

it('grants nothing for a subscription checkout, an unknown pack or a checkout it did not start', function () {
    Log::spy();

    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner, ['mode' => 'subscription']))->assertOk();
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner, ['metadata' => ['pack' => 'credits_1000']]))->assertOk();
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner, ['metadata' => []]))->assertOk();
    // Not started by our checkout (e.g. a payment link): no client_reference_id.
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner, ['client_reference_id' => null]))->assertOk();

    expect(creditsOf($this->acme))->toBeEmpty();
    Log::shouldHaveReceived('warning')->times(3);
});

it('grants nothing for a customer it does not know', function () {
    stripeWebhook('checkout.session.completed', [...stripeCreditsSession($this->acme, $this->owner), 'customer' => 'cus_unknown'])->assertOk();

    expect(AiCreditPurchase::withoutGlobalScopes()->count())->toBe(0);
});

it('records no buyer when the user is not a member any more', function () {
    $other = addMember(userInOrganization(name: 'Other')[1]);

    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $other))->assertOk();

    expect(creditsOf($this->acme)->sole()->user_id)->toBeNull()
        ->and(auditEntries($this->acme, AuditAction::AiCreditsPurchased)->sole()->actor_id)->toBeNull();
});

it('counts credits only in the month they were bought', function () {
    $this->travelTo('2026-10-31 23:00:00');
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner))->assertOk();
    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(15.0);

    $this->travelTo('2026-11-01 00:00:00');
    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(5.0);
});

it('lets AI keep working past the plan budget with credits', function () {
    recordAiUsage($this->acme, 6.0);
    $task = inOrganization($this->acme, fn () => Task::factory()->create(['description' => 'Something', 'created_by' => $this->owner->id]));

    $this->postJson("/api/tasks/{$task->id}/summary")->assertStatus(402);

    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner))->assertOk();

    $this->postJson("/api/tasks/{$task->id}/summary")->assertStatus(202);
});

it('adds credits on top of a lower own budget, but keeps AI off at 0', function () {
    stripeWebhook('checkout.session.completed', stripeCreditsSession($this->acme, $this->owner))->assertOk();

    $this->acme->forceFill(['ai_monthly_budget_usd' => 2])->save();
    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(12.0);

    $this->acme->forceFill(['ai_monthly_budget_usd' => 0])->save();
    expect(app(AiBudget::class)->limit($this->acme->fresh()))->toBe(0.0);
});

it('grants nothing to an organization deleted after its checkout started', function () {
    $this->acme->forceFill(['stripe_id' => 'cus_gone'])->save();
    $session = stripeCreditsSession($this->acme, $this->owner);
    $grant = app(GrantAiCredits::class);
    $this->acme->delete();

    expect($grant->handle($this->acme, $session))->toBeNull()
        ->and(AiCreditPurchase::withoutGlobalScope(OrganizationScope::class)->count())->toBe(0);
});

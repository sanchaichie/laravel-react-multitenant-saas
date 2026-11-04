<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5.0]);
    [$this->owner, $this->acme] = userInOrganization(name: 'Acme');
    // On Pro ($50), so budgets above Free's $5 are allowed (BillingBudgetTest covers Free).
    subscribe($this->acme);
    Sanctum::actingAs($this->owner);
});

it('sets the budget, goes back to the default, and turns AI off with 0', function () {
    recordAiUsage($this->acme, 1.0);

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 12.5])
        ->assertOk()
        ->assertJsonPath('data.limit_usd', 12.5)
        ->assertJsonPath('data.is_default', false)
        ->assertJsonPath('data.own_limit_usd', 12.5)
        ->assertJsonPath('data.remaining_usd', 11.5);
    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe('12.50');

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => '0'])->assertOk()->assertJsonPath('data.limit_usd', 0);
    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe('0.00');

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => null])
        ->assertOk()
        ->assertJsonPath('data.limit_usd', 50)
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('data.own_limit_usd', null);
    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBeNull();
});

it('records each change in the audit log, and nothing when it stays the same', function () {
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 0])->assertOk();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => '0.00'])->assertOk();
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => null])->assertOk();

    $entries = auditEntries($this->acme, AuditAction::AiBudgetUpdated);
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->actor_id)->toBe($this->owner->id)
        ->and($entries[0]->subject_label)->toBe('Acme')
        ->and($entries[0]->changes)->toEqual(['ai_monthly_budget_usd' => ['old' => null, 'new' => '0.00']])
        ->and($entries[1]->changes)->toEqual(['ai_monthly_budget_usd' => ['old' => '0.00', 'new' => null]]);
});

it('validates the budget', function (array $body, string $message) {
    $this->putJson('/api/ai-budget', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['monthly_budget_usd' => $message]);
})->with([
    'missing' => [[], 'The monthly budget usd field must be present.'],
    'negative' => [['monthly_budget_usd' => -1], 'The monthly budget usd field must be at least 0.'],
    'above the plan' => [['monthly_budget_usd' => 50.01], 'The Pro plan allows at most $50.00 a month. Buy AI credits for more.'],
    'cents only' => [['monthly_budget_usd' => '1.005'], 'Use at most 2 decimals (cents).'],
    'not a number' => [['monthly_budget_usd' => 'lots'], 'The monthly budget usd field must be a number.'],
]);

it('refuses roles without ai_budget.manage, Admin included', function () {
    Sanctum::actingAs(addMember($this->acme, DefaultRole::Admin));
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 10])->assertForbidden();

    Sanctum::actingAs(addMember($this->acme, createRole($this->acme, 'Viewer', [Permission::AiUsageView])));
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 10])->assertForbidden();

    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBeNull()
        ->and(auditEntries($this->acme, AuditAction::AiBudgetUpdated))->toHaveCount(0);
});

it('needs a verified email', function () {
    $this->owner->forceFill(['email_verified_at' => null])->save();

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 10])
        ->assertForbidden()
        ->assertJsonPath('code', 'email_unverified');
});

it('changes only the current organization\'s budget', function () {
    [, $beta] = userInOrganization(name: 'Beta');
    $this->owner->organizations()->attach($beta, ['role_id' => $beta->role(DefaultRole::Owner)->id]);

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 1])->assertOk();

    expect($this->acme->fresh()->ai_monthly_budget_usd)->toBe('1.00')
        ->and($beta->fresh()->ai_monthly_budget_usd)->toBeNull();
});

it('limits budget changes', function () {
    foreach (range(1, 20) as $i) {
        $this->putJson('/api/ai-budget', ['monthly_budget_usd' => $i])->assertOk();
    }

    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 1])->assertTooManyRequests();
});

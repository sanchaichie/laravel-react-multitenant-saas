<?php

use App\Enums\DefaultRole;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5.0]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    [$this->owner, $this->acme] = userInOrganization(name: 'Acme');
    $this->owner->forceFill(['name' => 'Olivia Owner'])->save();
    $this->member = addMember($this->acme, DefaultRole::Member, ['name' => 'Max Member']);
    Sanctum::actingAs($this->owner);
});

it('reports this month by feature, person and day, with the budget', function () {
    recordAiUsage($this->acme, 0.5, ['user_id' => $this->owner->id, 'created_at' => '2026-10-02 09:00:00']);
    recordAiUsage($this->acme, 0.25, ['user_id' => $this->owner->id, 'created_at' => '2026-10-14 09:00:00', 'status' => 'failed', 'error_code' => 'refusal', 'output_tokens' => 0]);
    recordAiUsage($this->acme, 0.125, ['user_id' => $this->member->id, 'created_at' => '2026-10-14 10:00:00', 'feature' => 'writing_help']);
    // Last month: not in this report.
    recordAiUsage($this->acme, 9.0, ['user_id' => $this->owner->id, 'created_at' => '2026-09-30 23:00:00']);

    $data = $this->getJson('/api/ai-usage')->assertOk()->json('data');

    expect($data['month'])->toBe('2026-10')
        ->and($data['totals'])->toBe(['calls' => 3, 'failed' => 1, 'input_tokens' => 3000, 'output_tokens' => 400, 'cost_usd' => 0.875])
        ->and($data['by_feature'])->toBe([
            ['feature' => 'task_summary', 'calls' => 2, 'failed' => 1, 'input_tokens' => 2000, 'output_tokens' => 200, 'cost_usd' => 0.75],
            ['feature' => 'writing_help', 'calls' => 1, 'failed' => 0, 'input_tokens' => 1000, 'output_tokens' => 200, 'cost_usd' => 0.125],
        ])
        ->and($data['by_user'])->toBe([
            ['user' => ['id' => $this->owner->id, 'name' => 'Olivia Owner'], 'calls' => 2, 'failed' => 1, 'input_tokens' => 2000, 'output_tokens' => 200, 'cost_usd' => 0.75],
            ['user' => ['id' => $this->member->id, 'name' => 'Max Member'], 'calls' => 1, 'failed' => 0, 'input_tokens' => 1000, 'output_tokens' => 200, 'cost_usd' => 0.125],
        ])
        ->and(array_column($data['by_day'], 'day'))->toBe(['2026-10-02', '2026-10-14'])
        ->and(array_column($data['by_day'], 'calls'))->toBe([1, 2])
        // toEqual: JSON drops the .0 of 5.0.
        ->and($data['budget'])->toEqual([
            'limit_usd' => 5.0,
            'is_default' => true,
            'own_limit_usd' => null,
            'default_limit_usd' => 5.0,
            'spent_usd' => 0.875,
            'remaining_usd' => 4.125,
        ])
        ->and($data['can'])->toBe(['manage_budget' => true]);
});

it('shows another month, with this month\'s budget', function () {
    recordAiUsage($this->acme, 9.0, ['created_at' => '2026-09-30 23:00:00']);
    recordAiUsage($this->acme, 1.0);

    $this->getJson('/api/ai-usage?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-09')
        ->assertJsonPath('data.totals.calls', 1)
        ->assertJsonPath('data.totals.cost_usd', 9)
        ->assertJsonPath('data.budget.spent_usd', 1);
});

it('returns an empty month', function () {
    $this->getJson('/api/ai-usage?month=2026-01')
        ->assertOk()
        ->assertJsonPath('data.totals', ['calls' => 0, 'failed' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0])
        ->assertJsonPath('data.by_feature', [])
        ->assertJsonPath('data.by_user', [])
        ->assertJsonPath('data.by_day', []);
});

it('shows work without a person, or by a deleted user, as no one', function () {
    $gone = addMember($this->acme);
    recordAiUsage($this->acme, 0.5, ['user_id' => $gone->id]);
    recordAiUsage($this->acme, 0.25, ['user_id' => null]);
    $gone->delete();

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.by_user.0.user', null)
        ->assertJsonPath('data.by_user.0.calls', 2);
});

it('validates the month', function (string $month, string $message) {
    $this->getJson('/api/ai-usage?month='.$month)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month' => $message]);
})->with([
    'not a month' => ['october', 'The month must look like 2026-10.'],
    'a day' => ['2026-10-01', 'The month must look like 2026-10.'],
    'the future' => ['2026-11', 'The month can\'t be in the future.'],
]);

it('tells the app whether the budget can be changed', function () {
    Sanctum::actingAs(addMember($this->acme, DefaultRole::Admin));

    $this->getJson('/api/ai-usage')->assertOk()->assertJsonPath('data.can', ['manage_budget' => false]);
});

it('never shows another organization\'s usage', function () {
    [$betaOwner, $beta] = userInOrganization(name: 'Beta');
    recordAiUsage($this->acme, 0.5, ['user_id' => $this->owner->id]);
    recordAiUsage($beta, 2.0, ['user_id' => $betaOwner->id]);

    $this->getJson('/api/ai-usage')
        ->assertJsonPath('data.totals.calls', 1)
        ->assertJsonPath('data.by_user.0.user.id', $this->owner->id)
        ->assertJsonPath('data.budget.spent_usd', 0.5);

    // Asking with another organization's id in the header changes nothing: it's a precondition only.
    $this->getJson('/api/ai-usage', ['X-Organization-Id' => (string) $beta->id])->assertStatus(409);
});

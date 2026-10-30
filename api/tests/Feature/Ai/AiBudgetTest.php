<?php

use App\Ai\AiBudget;
use App\Ai\AiRequest;
use App\Ai\Contracts\AiClient;
use App\Ai\Exceptions\AiBudgetExceeded;
use App\Enums\AuditAction;
use App\Enums\TaskSummaryStatus;
use App\Exceptions\NoCurrentOrganization;
use App\Jobs\GenerateTaskSummary;
use App\Models\AiUsage;
use App\Models\Task;
use App\Models\TaskSummary;
use App\Support\CurrentOrganization;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5.0]);
    [$this->owner, $this->acme] = userInOrganization(name: 'Acme');
    $this->task = inOrganization($this->acme, fn () => Task::factory()->create([
        'description' => 'Rebuild the marketing site.',
        'created_by' => $this->owner->id,
    ]));
    $this->budget = app(AiBudget::class);
});

it('uses the default budget, the organization\'s own, or none when it is 0', function () {
    expect($this->budget->summary($this->acme))->toBe([
        'limit_usd' => 5.0,
        'is_default' => true,
        'own_limit_usd' => null,
        'default_limit_usd' => 5.0,
        'spent_usd' => 0.0,
        'remaining_usd' => 5.0,
    ]);

    $this->acme->forceFill(['ai_monthly_budget_usd' => '12.50'])->save();
    expect($this->budget->summary($this->acme))
        ->limit_usd->toBe(12.5)
        ->is_default->toBeFalse()
        ->own_limit_usd->toBe(12.5);

    $this->acme->forceFill(['ai_monthly_budget_usd' => '0'])->save();
    expect($this->budget->limit($this->acme))->toBe(0.0)
        ->and($this->budget->exceeded($this->acme))->toBeTrue();
});

it('reads AI_MONTHLY_BUDGET_USD, and treats a blank one as the default', function (string $value, float $expected) {
    $_SERVER['AI_MONTHLY_BUDGET_USD'] = $value;

    try {
        expect((require config_path('ai.php'))['monthly_budget_usd'])->toBe($expected);
    } finally {
        unset($_SERVER['AI_MONTHLY_BUDGET_USD']);
    }
})->with([
    'blank' => ['', 5.0],
    'set' => ['12.5', 12.5],
    'off' => ['0', 0.0],
]);

it('counts only this UTC month and only this organization', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    [, $beta] = userInOrganization(name: 'Beta');

    recordAiUsage($this->acme, 1.0, ['created_at' => '2026-09-30 23:59:59']);
    recordAiUsage($this->acme, 0.5, ['created_at' => '2026-10-01 00:00:00']);
    recordAiUsage($this->acme, 0.25);
    recordAiUsage($this->acme, 2.0, ['created_at' => '2026-11-01 00:00:00']);
    recordAiUsage($beta, 3.0);

    expect($this->budget->spent($this->acme))->toBe(0.75)
        ->and($this->budget->spent($this->acme, CarbonImmutable::parse('2026-09-10', 'UTC')))->toBe(1.0)
        ->and($this->budget->remaining($this->acme))->toBe(4.25)
        // Beta's spend never counts toward Acme's budget.
        ->and($this->budget->spent($beta))->toBe(3.0);
});

it('refuses a call over budget before it reaches the provider or ai_usage', function () {
    recordAiUsage($this->acme, 5.0);

    inOrganization($this->acme, fn () => expect(fn () => app(AiClient::class)->generate(new AiRequest(feature: 'task_summary', system: 'Test', messages: [['role' => 'user', 'content' => 'Hi']])))
        ->toThrow(AiBudgetExceeded::class));

    $this->ai->assertNothingSent();
    expect(AiUsage::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses a call made outside any organization, since nobody would be billed', function () {
    app(CurrentOrganization::class)->forget();

    expect(fn () => app(AiClient::class)->generate(new AiRequest(feature: 'task_summary', system: 'Test', messages: [['role' => 'user', 'content' => 'Hi']])))
        ->toThrow(NoCurrentOrganization::class);

    $this->ai->assertNothingSent();
    expect(AiUsage::withoutGlobalScopes()->count())->toBe(0);
});

it('answers 402 to a summary request over budget, and queues nothing', function () {
    Sanctum::actingAs($this->owner);
    recordAiUsage($this->acme, 5.0);

    $this->postJson("/api/tasks/{$this->task->id}/summary")
        ->assertStatus(402)
        ->assertExactJson(['message' => 'This organization\'s monthly AI budget is used up.', 'code' => 'ai_budget_exceeded']);

    $this->ai->assertNothingSent();
    expect(TaskSummary::withoutGlobalScopes()->count())->toBe(0)
        ->and(auditEntries($this->acme, AuditAction::SummaryRequested))->toHaveCount(0);
});

it('answers 402 when the organization turned AI off', function () {
    Sanctum::actingAs($this->owner);
    $this->acme->forceFill(['ai_monthly_budget_usd' => '0'])->save();

    $this->postJson("/api/tasks/{$this->task->id}/summary")->assertStatus(402);
});

it('still returns the summary already being generated when over budget', function () {
    Sanctum::actingAs($this->owner);
    $pending = createSummary($this->acme, $this->task, TaskSummaryStatus::Pending);
    recordAiUsage($this->acme, 5.0);

    $this->postJson("/api/tasks/{$this->task->id}/summary")
        ->assertStatus(202)
        ->assertJsonPath('data.id', $pending->id)
        ->assertJsonPath('data.status', 'pending');
});

it('fails a summary whose job runs after the budget is spent', function () {
    $pending = createSummary($this->acme, $this->task, TaskSummaryStatus::Pending);
    recordAiUsage($this->acme, 5.0);

    GenerateTaskSummary::dispatch($this->acme->id, $pending->id);

    $this->ai->assertNothingSent();
    expect($pending->fresh())->status->toBe(TaskSummaryStatus::Failed)->error->toBe('budget_exceeded')
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(1);

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/tasks/{$this->task->id}/summary")
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', 'This organization\'s monthly AI budget is used up.');
});

it('creates the task over budget, without a summary', function () {
    Sanctum::actingAs($this->owner);
    recordAiUsage($this->acme, 5.0);

    $this->postJson('/api/tasks', ['title' => 'New', 'description' => 'Something to summarize.'])->assertCreated();

    $this->ai->assertNothingSent();
    expect(TaskSummary::withoutGlobalScopes()->count())->toBe(0);
});

it('skips organizations over budget in the backfill', function () {
    recordAiUsage($this->acme, 5.0);

    $this->artisan('ai:summarize-tasks')
        ->expectsOutputToContain("Acme (#{$this->acme->id}): skipped, the monthly AI budget is used up.")
        ->expectsOutputToContain('Queued 0 summaries.')
        ->assertSuccessful();

    expect(TaskSummary::withoutGlobalScopes()->count())->toBe(0);
});

it('stops the backfill when the budget runs out', function () {
    inOrganization($this->acme, fn () => Task::factory()->create(['description' => 'Another one.', 'created_by' => $this->owner->id]));
    // The first summary (a fake call costs $0.000035) uses up what's left.
    $this->acme->forceFill(['ai_monthly_budget_usd' => '0.01'])->save();
    recordAiUsage($this->acme, 0.00999);

    $this->artisan('ai:summarize-tasks')
        ->expectsOutputToContain("Acme (#{$this->acme->id}): queued 1, then the monthly AI budget ran out.")
        ->expectsOutputToContain('Queued 1 summaries.')
        ->assertSuccessful();

    expect(TaskSummary::withoutGlobalScopes()->pluck('task_id')->all())->toBe([$this->task->id]);
});

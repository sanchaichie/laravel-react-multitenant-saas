<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Task;
use App\Models\TaskSummary;
use Laravel\Sanctum\Sanctum;

// One person in two organizations: everything AI follows the current one only.

beforeEach(function () {
    config(['ai.monthly_budget_usd' => 5.0]);
    [$this->ada, $this->acme] = userInOrganization(name: 'Acme');
    [, $this->beta] = userInOrganization(name: 'Beta');
    $this->ada->organizations()->attach($this->beta, ['role_id' => $this->beta->role(DefaultRole::Owner)->id]);
    // Acme stays current.
    Sanctum::actingAs($this->ada);
});

it('reports only the current organization\'s usage', function () {
    recordAiUsage($this->acme, 0.5, ['user_id' => $this->ada->id]);
    recordAiUsage($this->beta, 2.0, ['user_id' => $this->ada->id]);

    $this->getJson('/api/ai-usage')
        ->assertOk()
        ->assertJsonPath('data.totals.calls', 1)
        ->assertJsonPath('data.by_user.0.cost_usd', 0.5)
        ->assertJsonPath('data.budget.spent_usd', 0.5);
});

it('checks ai.use in the current organization only', function () {
    // In Acme, a role that edits tasks but has no ai.use; Beta (owner) has everything.
    $role = createRole($this->acme, 'Quiet writer', [Permission::TasksCreate]);
    [$sam, $acme] = [addMember($this->acme, $role), $this->acme];
    $sam->organizations()->attach($this->beta, ['role_id' => $this->beta->role(DefaultRole::Owner)->id]);
    $task = inOrganization($acme, fn () => Task::factory()->create(['description' => 'Something.', 'created_by' => $sam->id]));
    Sanctum::actingAs($sam);

    $this->postJson("/api/tasks/{$task->id}/summary")->assertForbidden();
    $this->postJson('/api/tasks', ['title' => 'New', 'description' => 'Something to summarize.'])->assertCreated();

    $this->ai->assertNothingSent();
    expect(TaskSummary::withoutGlobalScopes()->count())->toBe(0);
});

it('counts each organization\'s budget apart', function () {
    $task = inOrganization($this->acme, fn () => Task::factory()->create(['description' => 'Something.', 'created_by' => $this->ada->id]));
    recordAiUsage($this->beta, 5.0);

    $this->postJson("/api/tasks/{$task->id}/summary")->assertStatus(202);

    recordAiUsage($this->acme, 5.0);
    $this->ada->forceFill(['current_organization_id' => $this->beta->id])->save();
    $betaTask = inOrganization($this->beta, fn () => Task::factory()->create(['description' => 'Other.', 'created_by' => $this->ada->id]));
    $this->postJson("/api/tasks/{$betaTask->id}/summary")->assertStatus(402);
});

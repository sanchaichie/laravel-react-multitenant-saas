<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\TaskSummaryStatus;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// Who may ask for AI work, see the usage and change the budget.

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization(name: 'Acme');
});

/**
 * A member with the role, and a task with a description they created.
 *
 * @return array{0: User, 1: Task}
 */
function memberWithOwnTask(DefaultRole|Role $role): array
{
    $user = $role === DefaultRole::Owner ? test()->owner : addMember(test()->acme, $role);
    $task = inOrganization(test()->acme, fn () => Task::factory()->create(['description' => 'Something.', 'created_by' => $user->id]));

    return [$user, $task];
}

it('applies the AI permission table', function (string $role, bool $summarize, bool $viewUsage, bool $manageBudget) {
    $role = match ($role) {
        'custom with ai.use' => createRole($this->acme, 'Writer', [Permission::TasksCreate, Permission::AiUse]),
        'custom without ai.use' => createRole($this->acme, 'Quiet writer', [Permission::TasksCreate]),
        'custom with usage and budget' => createRole($this->acme, 'Treasurer', [Permission::AiUsageView, Permission::AiBudgetManage]),
        default => DefaultRole::from($role),
    };
    [$user, $task] = memberWithOwnTask($role);
    Sanctum::actingAs($user);

    $this->postJson("/api/tasks/{$task->id}/summary")->assertStatus($summarize ? 202 : 403);
    $this->getJson("/api/tasks/{$task->id}/summary")->assertJsonPath('can.regenerate', $summarize);
    $this->getJson('/api/ai-usage')->assertStatus($viewUsage ? 200 : 403);
    $this->putJson('/api/ai-budget', ['monthly_budget_usd' => 3])->assertStatus($manageBudget ? 200 : 403);
})->with([
    'owner' => ['owner', true, true, true],
    'admin' => ['admin', true, true, false],
    'member' => ['member', true, false, false],
    'custom with ai.use' => ['custom with ai.use', true, false, false],
    'custom without ai.use' => ['custom without ai.use', false, false, false],
    // ai_budget.manage alone doesn't let it ask for summaries (no ai.use, no task edit).
    'custom with usage and budget' => ['custom with usage and budget', false, true, true],
]);

it('still needs edit access to the task, ai.use or not', function () {
    $member = addMember($this->acme);
    $othersTask = inOrganization($this->acme, fn () => Task::factory()->create(['description' => 'Not theirs.', 'created_by' => $this->owner->id]));
    Sanctum::actingAs($member);

    $this->postJson("/api/tasks/{$othersTask->id}/summary")->assertForbidden();
});

it('lets someone without ai.use still review a draft of a task they may edit', function () {
    [$user, $task] = memberWithOwnTask(createRole($this->acme, 'Quiet writer', [Permission::TasksCreate]));
    $draft = createSummary($this->acme, $task, TaskSummaryStatus::Draft);
    Sanctum::actingAs($user);

    $this->getJson("/api/tasks/{$task->id}/summary")->assertJsonPath('data.can.review', true);
    $this->postJson("/api/tasks/{$task->id}/summaries/{$draft->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('skips the automatic summary when the creator has no ai.use', function () {
    $user = addMember($this->acme, createRole($this->acme, 'Quiet writer', [Permission::TasksCreate]));
    Sanctum::actingAs($user);

    $this->postJson('/api/tasks', ['title' => 'New', 'description' => 'Something to summarize.'])->assertCreated();

    $this->ai->assertNothingSent();
    expect(TaskSummary::withoutGlobalScopes()->count())->toBe(0);
});

it('skips tasks of creators without ai.use in the backfill', function () {
    [, $task] = memberWithOwnTask(createRole($this->acme, 'Quiet writer', [Permission::TasksCreate]));

    $this->artisan('ai:summarize-tasks')
        ->expectsOutputToContain('nothing to queue, skipped 1 whose creator left, can\'t edit them or use AI, or isn\'t verified.')
        ->assertSuccessful();

    expect(TaskSummary::withoutGlobalScopes()->where('task_id', $task->id)->exists())->toBeFalse();
});

it('grants the AI permissions to existing roles by what they can do, not by name', function () {
    $migration = require database_path('migrations/2026_10_30_170001_grant_ai_permissions.php');
    $writer = createRole($this->acme, 'Writer', [Permission::TasksCreate]);
    $editor = createRole($this->acme, 'Editor', [Permission::TasksManage]);
    $manager = createRole($this->acme, 'People', [Permission::MembersManage]);
    $viewer = createRole($this->acme, 'Viewer');
    // As before this plan: the defaults without the AI permissions.
    $strip = fn (Role $role, array $permissions) => DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
    $strip($this->acme->role(DefaultRole::Admin), ['tasks.create', 'tasks.manage', 'members.invite', 'members.manage', 'audit_log.view']);
    $strip($this->acme->role(DefaultRole::Member), ['tasks.create']);
    $ownerPermissions = DB::table('roles')->where('id', $this->acme->role(DefaultRole::Owner)->id)->value('permissions');

    $migration->up();
    $migration->up();

    $permissions = fn (Role $role) => $role->fresh()->permissions->map(fn (Permission $p) => $p->value)->all();
    expect($permissions($this->acme->role(DefaultRole::Admin)))->toBe(['tasks.create', 'tasks.manage', 'members.invite', 'members.manage', 'audit_log.view', 'ai.use', 'ai_usage.view'])
        ->and($permissions($this->acme->role(DefaultRole::Member)))->toBe(['tasks.create', 'ai.use'])
        ->and($permissions($writer))->toBe(['tasks.create', 'ai.use'])
        ->and($permissions($editor))->toBe(['tasks.manage', 'ai.use'])
        ->and($permissions($manager))->toBe(['members.manage', 'ai_usage.view'])
        ->and($permissions($viewer))->toBe([])
        ->and(DB::table('roles')->where('id', $this->acme->role(DefaultRole::Owner)->id)->value('permissions'))->toBe($ownerPermissions);

    $migration->down();
    expect($permissions($writer))->toBe(['tasks.create'])
        ->and($permissions($editor))->toBe(['tasks.manage'])
        ->and($permissions($manager))->toBe(['members.manage']);
});

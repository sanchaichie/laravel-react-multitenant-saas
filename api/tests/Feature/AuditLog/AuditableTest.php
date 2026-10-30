<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Model changes recorded by the Auditable trait (Task, Role).

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner, 'Acme');
    $this->owner->update(['name' => 'Maya']);
    Sanctum::actingAs($this->owner);
});

it('records creating, editing and deleting a task, with what changed', function () {
    $ana = addMember($this->organization, attributes: ['name' => 'Ana']);
    $ben = addMember($this->organization, attributes: ['name' => 'Ben']);

    $id = $this->postJson('/api/tasks', ['title' => 'Write docs', 'priority' => 'high', 'assignee_id' => $ana->id, 'due_date' => '2026-11-20'])
        ->assertCreated()->json('data.id');
    $this->patchJson("/api/tasks/{$id}", ['title' => 'Write the docs', 'assignee_id' => $ben->id])->assertOk();
    $this->deleteJson("/api/tasks/{$id}")->assertNoContent();

    [$created, $updated, $deleted] = auditEntries($this->organization)->all();

    expect($created->action)->toBe(AuditAction::TaskCreated)
        ->and($created->actor_id)->toBe($this->owner->id)
        ->and($created->organization_id)->toBe($this->organization->id)
        ->and($created->subject_type)->toBe('task')
        ->and($created->subject_id)->toBe($id)
        ->and($created->subject_label)->toBe('Write docs')
        ->and($created->changes['title'])->toEqual(['old' => null, 'new' => 'Write docs'])
        ->and($created->changes['priority'])->toEqual(['old' => null, 'new' => 'high'])
        ->and($created->changes['due_date'])->toEqual(['old' => null, 'new' => '2026-11-20'])
        ->and($created->changes['assignee'])->toEqual(['old' => null, 'new' => ['id' => $ana->id, 'name' => 'Ana']])
        // Keys, timestamps and the creator (the actor already shows them) aren't recorded.
        ->and($created->changes)->not->toHaveKeys(['id', 'organization_id', 'created_by', 'created_at', 'updated_at', 'assignee_id']);

    expect($updated->action)->toBe(AuditAction::TaskUpdated)
        ->and($updated->subject_label)->toBe('Write the docs')
        ->and($updated->changes)->toEqual([
            'title' => ['old' => 'Write docs', 'new' => 'Write the docs'],
            'assignee' => ['old' => ['id' => $ana->id, 'name' => 'Ana'], 'new' => ['id' => $ben->id, 'name' => 'Ben']],
        ]);

    expect($deleted->action)->toBe(AuditAction::TaskDeleted)
        ->and($deleted->subject_label)->toBe('Write the docs')
        ->and($deleted->changes['title'])->toEqual(['old' => 'Write the docs', 'new' => null]);
});

it('records nothing for an edit that changes nothing', function () {
    $task = inOrganization($this->organization, fn () => Task::factory()->create(['title' => 'Same']));

    $this->patchJson("/api/tasks/{$task->id}", ['title' => 'Same', 'status' => 'todo'])->assertOk();
    // Saved, but only columns that are never recorded changed.
    inOrganization($this->organization, fn () => $task->forceFill(['created_by' => $this->owner->id])->touch());

    expect(auditEntries($this->organization, AuditAction::TaskUpdated))->toHaveCount(0);
});

it('shortens long values it stores, but still sees a change past the cut', function () {
    $long = str_repeat('a', 600);
    $task = inOrganization($this->organization, fn () => Task::factory()->create(['description' => $long]));

    $this->patchJson("/api/tasks/{$task->id}", ['description' => $long.'b'])->assertOk();

    $changes = auditEntries($this->organization, AuditAction::TaskUpdated)->sole()->changes;
    expect(mb_strlen($changes['description']['new']))->toEqual(501)
        ->and($changes['description']['new'])->toEndWith('…');
});

it('records role changes, permissions included', function () {
    $id = $this->postJson('/api/roles', ['name' => 'Lead', 'permissions' => ['tasks.create']])->assertCreated()->json('data.id');
    $this->patchJson("/api/roles/{$id}", ['name' => 'Lead', 'permissions' => ['tasks.create', 'tasks.manage']])->assertOk();
    $this->deleteJson("/api/roles/{$id}")->assertNoContent();

    [$created, $updated, $deleted] = auditEntries($this->organization)->all();

    expect($created->action)->toBe(AuditAction::RoleCreated)
        ->and($created->subject_label)->toBe('Lead')
        ->and($created->changes)->toEqual([
            'name' => ['old' => null, 'new' => 'Lead'],
            'permissions' => ['old' => null, 'new' => ['tasks.create']],
        ])
        ->and($updated->changes)->toEqual(['permissions' => ['old' => ['tasks.create'], 'new' => ['tasks.create', 'tasks.manage']]])
        ->and($deleted->action)->toBe(AuditAction::RoleDeleted)
        ->and($deleted->subject_label)->toBe('Lead');
});

it('records creating an organization once, without its default roles', function () {
    $id = $this->postJson('/api/organizations', ['name' => 'Beta'])->assertCreated()->json('data.id');

    $entries = auditEntries()->where('organization_id', $id);
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->action)->toBe(AuditAction::OrganizationCreated)
        ->and($entries->first()->actor_id)->toBe($this->owner->id)
        ->and($entries->first()->subject_label)->toBe('Beta')
        // Not in the organization the user was acting in before.
        ->and(auditEntries($this->organization))->toHaveCount(0);
});

it('names the new user as the creator of their personal organization when registering', function () {
    auth()->forgetGuards();
    $this->postJson('/api/register', [
        'name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'password-123456', 'password_confirmation' => 'password-123456',
    ])->assertCreated();

    $user = User::query()->where('email', 'ana@example.com')->sole();
    $entry = auditEntries()->where('organization_id', $user->current_organization_id)->sole();
    expect($entry->action)->toBe(AuditAction::OrganizationCreated)
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->ip_address)->not->toBeNull();
});

it('refuses to record a change to another organization\'s model', function () {
    [, $other] = userInOrganization(name: 'Other');
    $role = createRole($other, 'Viewer');

    // Acting in Acme, changing Other's role (only a bug could get here).
    inOrganization($this->organization, fn () => $role->update(['permissions' => [Permission::TasksCreate]]));
})->throws(LogicException::class, 'An audited model can only be changed while acting in its organization.');

it('can be turned off for setup code', function () {
    Role::withoutAuditing(fn () => createRole($this->organization, 'Quiet'));

    expect(auditEntries($this->organization))->toHaveCount(0);
});

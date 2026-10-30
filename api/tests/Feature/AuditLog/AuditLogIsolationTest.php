<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\Role;
use App\Models\Task;
use Laravel\Sanctum\Sanctum;

// Org A cannot read org B's audit log.

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    Sanctum::actingAs($this->ownerA);
    $this->postJson('/api/tasks', ['title' => 'Task in A'])->assertCreated();
    Sanctum::actingAs($this->ownerB);
    $this->postJson('/api/tasks', ['title' => 'Secret task in B'])->assertCreated();
});

it('never lists another organization\'s entries, whatever the filter', function () {
    Sanctum::actingAs($this->ownerA);

    $labels = fn (string $query = '') => collect($this->getJson("/api/audit-logs{$query}")->assertOk()->json('data'))->pluck('subject.label')->all();

    expect($labels())->toBe(['Task in A'])
        ->and($labels('?action=task.created'))->toBe(['Task in A'])
        // B's owner as the actor: their entries are B's, so nothing.
        ->and($labels("?actor={$this->ownerB->id}"))->toBe([]);
});

it('shows a member of both only the current organization\'s log', function () {
    $both = addMember($this->orgB, DefaultRole::Admin);
    $this->orgA->members()->attach($both, ['role_id' => $this->orgA->role(DefaultRole::Admin)->id]);
    $both->forceFill(['current_organization_id' => $this->orgA->id])->save();
    Sanctum::actingAs($both);

    expect(collect($this->getJson('/api/audit-logs')->assertOk()->json('data'))->pluck('subject.label')->all())->toBe(['Task in A']);
});

it('writes each change to its own organization\'s log', function () {
    expect(auditEntries($this->orgA)->pluck('subject_label')->all())->toBe(['Task in A'])
        ->and(auditEntries($this->orgB)->pluck('subject_label')->all())->toBe(['Secret task in B'])
        ->and(auditEntries($this->orgB, AuditAction::TaskCreated)->sole()->actor_id)->toBe($this->ownerB->id);
});

it('doesn\'t let a task of B be changed while acting in A, even by mistake', function () {
    $taskB = inOrganization($this->orgB, fn () => Task::query()->sole());

    inOrganization($this->orgA, fn () => $taskB->update(['title' => 'Changed']));
})->throws(LogicException::class);

it('writes nothing for an attempt on another organization\'s records', function () {
    $taskB = inOrganization($this->orgB, fn () => Task::query()->sole());
    [$invitationB] = createInvitation($this->orgB, 'ana@example.com');
    $roleB = inOrganization($this->orgB, fn () => Role::query()->where('is_owner', false)->firstOrFail());
    $before = [auditEntries($this->orgA)->count(), auditEntries($this->orgB)->count()];
    Sanctum::actingAs($this->ownerA);

    $this->patchJson("/api/tasks/{$taskB->id}", ['title' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/tasks/{$taskB->id}")->assertNotFound();
    $this->deleteJson("/api/invitations/{$invitationB->id}")->assertNotFound();
    $this->patchJson("/api/roles/{$roleB->id}", ['name' => 'Hacked', 'permissions' => []])->assertNotFound();

    expect([auditEntries($this->orgA)->count(), auditEntries($this->orgB)->count()])->toBe($before);
});

it('keeps a cursor from another organization inside the current one', function () {
    Sanctum::actingAs($this->ownerB);
    // B's first page, as a cursor that points past B's newest entry.
    $cursor = $this->getJson('/api/audit-logs')->assertOk()->json('meta.next_cursor')
        ?? base64_encode(json_encode(['id' => auditEntries($this->orgB)->last()->id + 1, '_pointsToNextItems' => true]));

    Sanctum::actingAs($this->ownerA);
    $labels = collect($this->getJson('/api/audit-logs?cursor='.rawurlencode($cursor))->assertOk()->json('data'))->pluck('subject.label')->all();

    expect($labels)->not->toContain('Secret task in B');
});

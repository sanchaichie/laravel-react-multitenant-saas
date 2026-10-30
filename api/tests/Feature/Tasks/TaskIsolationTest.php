<?php

use App\Enums\DefaultRole;
use App\Models\Task;
use Laravel\Sanctum\Sanctum;

// Org A cannot read or write org B's tasks.

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');
    $this->memberB = addMember($this->orgB);

    $this->taskA = inOrganization($this->orgA, fn () => Task::factory()->create(['title' => 'Shared word A']));
    $this->taskB = inOrganization($this->orgB, fn () => Task::factory()->assignedTo($this->memberB)->create([
        'title' => 'Shared word B',
        'description' => 'secret',
    ]));
});

/** @return list<int> */
function listedIds(string $query = ''): array
{
    return collect(test()->getJson("/api/tasks{$query}")->assertOk()->json('data'))->pluck('id')->all();
}

it('never lists another organization\'s tasks, whatever the filter', function () {
    Sanctum::actingAs($this->ownerA);

    expect(listedIds())->toBe([$this->taskA->id])
        ->and(listedIds('?search=shared'))->toBe([$this->taskA->id])
        ->and(listedIds('?search=secret'))->toBe([])
        ->and(listedIds("?assignee={$this->memberB->id}"))->toBe([])
        ->and(listedIds('?status=todo'))->toBe([$this->taskA->id]);
});

it('gets 404 on another organization\'s task, which stays unchanged', function () {
    Sanctum::actingAs($this->ownerA);

    $this->getJson("/api/tasks/{$this->taskB->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->patchJson("/api/tasks/{$this->taskB->id}", ['title' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/tasks/{$this->taskB->id}")->assertNotFound();

    $taskB = Task::withoutGlobalScopes()->findOrFail($this->taskB->id);
    expect($taskB->title)->toBe('Shared word B')
        ->and($taskB->organization_id)->toBe($this->orgB->id);
});

it('can\'t assign one of another organization\'s members', function () {
    Sanctum::actingAs($this->ownerA);

    $this->postJson('/api/tasks', ['title' => 'x', 'assignee_id' => $this->memberB->id])->assertJsonValidationErrors('assignee_id');
    $this->patchJson("/api/tasks/{$this->taskA->id}", ['assignee_id' => $this->ownerB->id])->assertJsonValidationErrors('assignee_id');

    expect($this->taskA->fresh()->assignee_id)->toBeNull();
});

it('stays isolated for someone in both organizations who hasn\'t switched', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);
    Sanctum::actingAs($this->ownerA);

    expect(listedIds())->toBe([$this->taskA->id]);
    $this->getJson("/api/tasks/{$this->taskB->id}")->assertNotFound();
    $this->patchJson("/api/tasks/{$this->taskB->id}", ['title' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/tasks/{$this->taskB->id}")->assertNotFound();
    // Acting in A, B's members aren't assignable even though A's owner is in B too.
    $this->postJson('/api/tasks', ['title' => 'x', 'assignee_id' => $this->memberB->id])->assertJsonValidationErrors('assignee_id');

    expect(Task::withoutGlobalScopes()->findOrFail($this->taskB->id)->title)->toBe('Shared word B');
});

it('creates tasks in the current organization only', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);
    Sanctum::actingAs($this->ownerA);

    $id = $this->postJson('/api/tasks', ['title' => 'Where?', 'organization_id' => $this->orgB->id])->assertCreated()->json('data.id');

    expect(Task::withoutGlobalScopes()->findOrFail($id)->organization_id)->toBe($this->orgA->id);
});

it('answers 409 before looking the task up, for a tab still on the previous organization', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);
    $this->ownerA->forceFill(['current_organization_id' => $this->orgB->id])->save();
    Sanctum::actingAs($this->ownerA);

    // The tab thinks A is current (another tab switched to B): 409, not a 404 from the
    // binding, so the app catches up instead of saying "not found".
    $this->withHeader('X-Organization-Id', (string) $this->orgA->id)
        ->getJson("/api/tasks/{$this->taskA->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'organization_changed');
});

<?php

use App\Enums\DefaultRole;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// The permission table of plan 07: role × (created by me, assigned to me, neither).

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner);
    $this->colleague = addMember($this->organization);
});

/**
 * A task related to $user as given: created by them, assigned to them, or neither
 * (created by and assigned to the colleague).
 */
function taskFor(User $user, string $relation): Task
{
    $colleague = test()->colleague;

    return inOrganization(test()->organization, fn () => Task::factory()->create([
        'created_by' => $relation === 'created' ? $user->id : $colleague->id,
        'assignee_id' => $relation === 'assigned' ? $user->id : $colleague->id,
    ]));
}

function actingWithRole(DefaultRole $role): User
{
    $user = $role === DefaultRole::Owner ? test()->owner : addMember(test()->organization, $role);
    Sanctum::actingAs($user);

    return $user;
}

it('lets every member list, see and create tasks', function (DefaultRole $role) {
    $user = actingWithRole($role);
    $task = taskFor($user, 'neither');

    $this->getJson('/api/tasks')->assertOk();
    $this->getJson("/api/tasks/{$task->id}")->assertOk();
    $this->postJson('/api/tasks', ['title' => 'New', 'assignee_id' => $this->colleague->id])->assertCreated();
})->with(DefaultRole::cases());

it('edits per the table', function (DefaultRole $role, string $relation, bool $allowed) {
    $user = actingWithRole($role);
    $task = taskFor($user, $relation);

    $this->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can.update', $allowed);
    $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])->assertStatus($allowed ? 200 : 403);

    expect($task->fresh()->status->value)->toBe($allowed ? 'done' : 'todo');
})->with([
    'owner, anyone\'s' => [DefaultRole::Owner, 'neither', true],
    'admin, anyone\'s' => [DefaultRole::Admin, 'neither', true],
    'member, created' => [DefaultRole::Member, 'created', true],
    'member, assigned' => [DefaultRole::Member, 'assigned', true],
    'member, someone else\'s' => [DefaultRole::Member, 'neither', false],
]);

it('deletes per the table', function (DefaultRole $role, string $relation, bool $allowed) {
    $user = actingWithRole($role);
    $task = taskFor($user, $relation);

    $this->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can.delete', $allowed);
    $this->deleteJson("/api/tasks/{$task->id}")->assertStatus($allowed ? 204 : 403);

    expect(Task::withoutGlobalScopes()->find($task->id) === null)->toBe($allowed);
})->with([
    'owner, anyone\'s' => [DefaultRole::Owner, 'neither', true],
    'admin, anyone\'s' => [DefaultRole::Admin, 'neither', true],
    'member, created' => [DefaultRole::Member, 'created', true],
    'member, assigned' => [DefaultRole::Member, 'assigned', false],
    'member, someone else\'s' => [DefaultRole::Member, 'neither', false],
]);

it('keeps the creator\'s rights when the task is reassigned, and loses the old assignee\'s', function () {
    $member = actingWithRole(DefaultRole::Member);
    $task = taskFor($member, 'assigned');

    $this->patchJson("/api/tasks/{$task->id}", ['assignee_id' => $this->colleague->id])->assertOk();
    $this->patchJson("/api/tasks/{$task->id}", ['title' => 'Again'])->assertForbidden();
});

<?php

use App\Enums\DefaultRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskCreated;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner);
    $this->member = addMember($this->organization, DefaultRole::Member, ['name' => 'Mia']);
    Sanctum::actingAs($this->owner);
});

it('creates a task with defaults, created_by and a TaskCreated event', function () {
    Event::fake([TaskCreated::class]);

    $response = $this->postJson('/api/tasks', ['title' => 'Write the docs'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Write the docs')
        ->assertJsonPath('data.status', 'todo')
        ->assertJsonPath('data.priority', 'medium')
        ->assertJsonPath('data.due_date', null)
        ->assertJsonPath('data.is_overdue', false)
        ->assertJsonPath('data.assignee', null)
        ->assertJsonPath('data.creator.id', $this->owner->id)
        ->assertJsonPath('data.can', ['update' => true, 'delete' => true]);

    $task = inOrganization($this->organization, fn () => Task::findOrFail($response->json('data.id')));
    expect($task->created_by)->toBe($this->owner->id)
        ->and($task->organization_id)->toBe($this->organization->id);

    Event::assertDispatched(TaskCreated::class, fn (TaskCreated $event) => $event->taskId === $task->id
        && $event->organizationId === $this->organization->id
        && $event->createdBy === $this->owner->id);
});

it('creates a task with every field', function () {
    $this->postJson('/api/tasks', [
        'title' => 'Launch',
        'description' => 'Ship it',
        'status' => 'in_progress',
        'priority' => 'high',
        'due_date' => '2030-01-15',
        'assignee_id' => $this->member->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.description', 'Ship it')
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.due_date', '2030-01-15')
        ->assertJsonPath('data.assignee', ['id' => $this->member->id, 'name' => 'Mia']);
});

it('ignores organization_id and created_by in the payload', function () {
    [, $other] = userInOrganization(DefaultRole::Owner, 'Other');

    $id = $this->postJson('/api/tasks', [
        'title' => 'Mine',
        'organization_id' => $other->id,
        'created_by' => $this->member->id,
    ])->assertCreated()->json('data.id');

    $task = Task::withoutGlobalScopes()->findOrFail($id);
    expect($task->organization_id)->toBe($this->organization->id)
        ->and($task->created_by)->toBe($this->owner->id);
});

it('shows a task', function () {
    $task = inOrganization($this->organization, fn () => Task::factory()->createdBy($this->owner)->assignedTo($this->member)->create(['title' => 'Read me']));

    $this->getJson("/api/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Read me')
        ->assertJsonPath('data.assignee.name', 'Mia')
        ->assertJsonPath('data.creator.id', $this->owner->id);
});

it('updates only the fields sent', function () {
    $task = inOrganization($this->organization, fn () => Task::factory()->createdBy($this->owner)->create(['title' => 'Old', 'priority' => TaskPriority::Low]));

    $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done', 'assignee_id' => $this->member->id])
        ->assertOk()
        ->assertJsonPath('data.title', 'Old')
        ->assertJsonPath('data.priority', 'low')
        ->assertJsonPath('data.status', 'done')
        ->assertJsonPath('data.assignee.id', $this->member->id);

    $this->patchJson("/api/tasks/{$task->id}", ['assignee_id' => null, 'due_date' => null])
        ->assertOk()
        ->assertJsonPath('data.assignee', null);

    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});

it('can\'t move a task to another organization or change its creator', function () {
    [, $other] = userInOrganization(DefaultRole::Owner, 'Other');
    $task = inOrganization($this->organization, fn () => Task::factory()->createdBy($this->owner)->create());

    $this->patchJson("/api/tasks/{$task->id}", ['organization_id' => $other->id, 'created_by' => $this->member->id, 'title' => 'Still here'])
        ->assertOk();

    $task = Task::withoutGlobalScopes()->findOrFail($task->id);
    expect($task->organization_id)->toBe($this->organization->id)
        ->and($task->created_by)->toBe($this->owner->id)
        ->and($task->title)->toBe('Still here');
});

it('deletes a task', function () {
    $task = inOrganization($this->organization, fn () => Task::factory()->create());

    $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    expect(Task::withoutGlobalScopes()->find($task->id))->toBeNull();
});

it('marks overdue tasks, but not done ones or ones due today', function () {
    [$late, $doneLate, $today] = inOrganization($this->organization, fn () => [
        Task::factory()->overdue()->create(),
        Task::factory()->overdue()->done()->create(),
        Task::factory()->create(['due_date' => today()->toDateString()]),
    ]);

    $this->getJson("/api/tasks/{$late->id}")->assertJsonPath('data.is_overdue', true);
    $this->getJson("/api/tasks/{$doneLate->id}")->assertJsonPath('data.is_overdue', false);
    $this->getJson("/api/tasks/{$today->id}")->assertJsonPath('data.is_overdue', false);
});

it('validates the fields', function (array $payload, string $field) {
    $this->postJson('/api/tasks', ['title' => 'Valid', ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no title' => [['title' => ''], 'title'],
    'long title' => [['title' => str_repeat('a', 256)], 'title'],
    'long description' => [['description' => str_repeat('a', 5001)], 'description'],
    'unknown status' => [['status' => 'blocked'], 'status'],
    'null status' => [['status' => null], 'status'],
    'unknown priority' => [['priority' => 'urgent'], 'priority'],
    'due date with a time' => [['due_date' => '2030-01-15 10:00:00'], 'due_date'],
    'due date in another format' => [['due_date' => '15/01/2030'], 'due_date'],
    'assignee not an id' => [['assignee_id' => 'mia'], 'assignee_id'],
]);

it('refuses an assignee who isn\'t a member', function () {
    $stranger = User::factory()->create();
    $task = inOrganization($this->organization, fn () => Task::factory()->create());

    $this->postJson('/api/tasks', ['title' => 'Nope', 'assignee_id' => $stranger->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignee_id' => 'The assignee must be a member of this organization.']);
    $this->patchJson("/api/tasks/{$task->id}", ['assignee_id' => $stranger->id])
        ->assertJsonValidationErrors('assignee_id');

    expect($task->fresh()->assignee_id)->toBeNull();
});

it('validates updates the same way, for the fields sent', function () {
    $task = inOrganization($this->organization, fn () => Task::factory()->create());

    $this->patchJson("/api/tasks/{$task->id}", ['title' => ''])->assertJsonValidationErrors('title');
    $this->patchJson("/api/tasks/{$task->id}", ['status' => 'blocked'])->assertJsonValidationErrors('status');
    $this->patchJson("/api/tasks/{$task->id}", [])->assertOk();
});

it('needs a login and a current organization', function () {
    $task = inOrganization($this->organization, fn () => Task::factory()->create());

    app('auth')->forgetGuards();
    $this->getJson('/api/tasks')->assertUnauthorized();

    $loner = User::factory()->create();
    Sanctum::actingAs($loner);
    $this->getJson('/api/tasks')->assertForbidden();
    $this->postJson('/api/tasks', ['title' => 'x'])->assertForbidden();
    $this->getJson("/api/tasks/{$task->id}")->assertForbidden();
});

it('returns 404 for ids that aren\'t tasks', function () {
    $this->getJson('/api/tasks/999999')->assertNotFound();
    $this->getJson('/api/tasks/abc')->assertNotFound();
    $this->getJson('/api/tasks/1234567890123456789')->assertNotFound();
});

<?php

use App\Actions\Tasks\CreateTask;
use App\Actions\Webhooks\QueueDelivery;
use App\Enums\DefaultRole;
use App\Jobs\DeliverWebhook;
use App\Models\Task;
use App\Models\WebhookDelivery;
use App\Webhooks\WebhookPayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    Sanctum::actingAs($this->owner);
});

function deliveriesOf($organization): Collection
{
    return inOrganization($organization, fn () => WebhookDelivery::query()->orderBy('id')->get());
}

it('queues one delivery per active endpoint subscribed to the event', function () {
    $all = createWebhook($this->acme);
    $createdOnly = createWebhook($this->acme, ['events' => ['task.created']]);
    createWebhook($this->acme, ['events' => ['task.updated']]);
    createWebhook($this->acme, ['disabled_at' => now(), 'disabled_reason' => 'manual']);

    $id = $this->postJson('/api/tasks', ['title' => 'Ship it', 'description' => 'Details'])->assertCreated()->json('data.id');

    $deliveries = deliveriesOf($this->acme);

    expect($deliveries->pluck('webhook_endpoint_id')->all())->toBe([$all->id, $createdOnly->id])
        ->and($deliveries->pluck('event')->unique()->all())->toBe(['task.created'])
        // One event: the same id and payload for every endpoint.
        ->and($deliveries->pluck('event_id')->unique())->toHaveCount(1)
        ->and($deliveries[0]->payload)->toEqual($deliveries[1]->payload)
        ->and($deliveries[0]->payload['data']['task'])->toMatchArray([
            'id' => $id, 'title' => 'Ship it', 'description' => 'Details', 'status' => 'todo',
            'priority' => 'medium', 'due_date' => null, 'assignee' => null, 'created_by' => $this->owner->id,
        ])
        ->and($deliveries[0]->status->value)->toBe('pending');

    Queue::assertPushed(DeliverWebhook::class, 2);
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->attempt === 1 && $job->organizationId === $this->acme->id);
});

it('sends task.updated with the changed fields, only when something changed', function () {
    createWebhook($this->acme);
    $member = addMember($this->acme);
    $task = inOrganization($this->acme, fn () => Task::factory()->createdBy($this->owner)->create(['status' => 'todo']));

    $this->patchJson("/api/tasks/{$task->id}", ['status' => 'todo'])->assertOk();
    expect(deliveriesOf($this->acme))->toHaveCount(0);

    $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done', 'assignee_id' => $member->id])->assertOk();

    $payload = deliveriesOf($this->acme)->sole()->payload;
    expect($payload['type'])->toBe('task.updated')
        ->and($payload['data']['changed'])->toEqualCanonicalizing(['status', 'assignee'])
        ->and($payload['data']['task']['assignee'])->toEqual(['id' => $member->id, 'name' => $member->name])
        ->and(json_encode($payload))->not->toContain($member->email);
});

it('sends the deleted task as it was', function () {
    createWebhook($this->acme);
    $task = inOrganization($this->acme, fn () => Task::factory()->createdBy($this->owner)->create(['title' => 'Gone soon']));

    $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    $payload = deliveriesOf($this->acme)->sole()->payload;
    expect($payload['type'])->toBe('task.deleted')
        ->and($payload['data']['task']['id'])->toBe($task->id)
        ->and($payload['data']['task']['title'])->toBe('Gone soon');
});

it('sends task.updated for each task a removed member is unassigned from', function () {
    createWebhook($this->acme);
    $member = addMember($this->acme);
    $tasks = inOrganization($this->acme, fn () => Task::factory()->count(2)->createdBy($this->owner)->create(['assignee_id' => $member->id]));
    inOrganization($this->acme, fn () => Task::factory()->createdBy($this->owner)->create());

    // They're also in Beta, assigned a task there, and Beta has a webhook: none of it moves.
    [, $beta] = userInOrganization(DefaultRole::Owner, 'Beta');
    $beta->members()->attach($member, ['role_id' => $beta->role(DefaultRole::Member)->id]);
    createWebhook($beta);
    $betaTask = inOrganization($beta, fn () => Task::factory()->create(['assignee_id' => $member->id]));

    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    expect(deliveriesOf($beta))->toHaveCount(0)
        ->and(Task::withoutGlobalScopes()->find($betaTask->id)->assignee_id)->toBe($member->id);

    $deliveries = deliveriesOf($this->acme);
    expect($deliveries->map(fn ($d) => $d->payload['data']['task']['id'])->sort()->values()->all())->toBe($tasks->pluck('id')->sort()->values()->all())
        ->and($deliveries->every(fn ($d) => $d->payload['data']['changed'] === ['assignee'] && $d->payload['data']['task']['assignee'] === null))->toBeTrue();
});

it('sends nothing for a change that rolls back', function () {
    createWebhook($this->acme);

    try {
        DB::transaction(function () {
            inOrganization($this->acme, fn () => app(CreateTask::class)->handle($this->owner, ['title' => 'Never']));
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(deliveriesOf($this->acme))->toHaveCount(0);
    Queue::assertNothingPushed();
});

it('never fails the task change when queueing fails', function () {
    createWebhook($this->acme);
    $this->mock(QueueDelivery::class)->shouldReceive('toEndpoints')->andThrow(new RuntimeException('queue down'));

    $this->postJson('/api/tasks', ['title' => 'Still saved'])->assertCreated();
});

it('builds no payload for an organization without webhooks', function () {
    $this->postJson('/api/tasks', ['title' => 'Quiet'])->assertCreated();

    expect(deliveriesOf($this->acme))->toHaveCount(0);
    Queue::assertNothingPushed();
});

it('names payload fields as the API does', function () {
    expect(WebhookPayload::changedFields(['status', 'assignee_id', 'updated_at']))->toBe(['status', 'assignee']);
});

<?php

use App\Ai\AiRequest;
use App\Enums\DefaultRole;
use App\Enums\TaskSummaryStatus;
use App\Jobs\GenerateTaskSummary;
use App\Models\AiUsage;
use App\Models\Task;
use App\Models\TaskSummary;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

// Org A cannot read or write org B's task summaries.

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    $this->taskA = inOrganization($this->orgA, fn () => Task::factory()->create(['description' => 'Org A task']));
    $this->taskB = inOrganization($this->orgB, fn () => Task::factory()->create(['description' => 'Org B secret']));
    $this->draftB = createSummary($this->orgB, $this->taskB, TaskSummaryStatus::Draft, ['summary' => 'B summary']);
});

it('gets 404 on another organization\'s task summary, which stays unchanged', function () {
    Sanctum::actingAs($this->ownerA);

    $this->getJson("/api/tasks/{$this->taskB->id}/summary")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/summary")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/summaries/{$this->draftB->id}/approve")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/summaries/{$this->draftB->id}/reject")->assertNotFound();

    expect(TaskSummary::withoutGlobalScopes()->find($this->draftB->id))
        ->status->toBe(TaskSummaryStatus::Draft)
        ->summary->toBe('B summary')
        ->and(TaskSummary::withoutGlobalScopes()->where('task_id', $this->taskB->id)->count())->toBe(1);
    $this->ai->assertNothingSent();
});

it('gets 404 on another organization\'s summary under its own task', function () {
    Sanctum::actingAs($this->ownerA);

    $this->postJson("/api/tasks/{$this->taskA->id}/summaries/{$this->draftB->id}/approve")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskA->id}/summaries/{$this->draftB->id}/reject")->assertNotFound();

    expect(TaskSummary::withoutGlobalScopes()->find($this->draftB->id)->status)->toBe(TaskSummaryStatus::Draft);
});

it('sends only the task\'s own text and records usage in its organization', function () {
    Sanctum::actingAs($this->ownerA);

    $this->postJson("/api/tasks/{$this->taskA->id}/summary")->assertStatus(202);

    $this->ai->assertSent(fn (AiRequest $request) => str_contains($request->messages[0]['content'], 'Org A task')
        && ! str_contains($request->messages[0]['content'].$request->system, 'Org B'));

    expect(AiUsage::withoutGlobalScopes()->pluck('organization_id')->all())->toBe([$this->orgA->id])
        ->and(TaskSummary::withoutGlobalScopes()->where('task_id', $this->taskA->id)->sole()->organization_id)->toBe($this->orgA->id);
});

it('stays isolated for someone in both organizations who hasn\'t switched', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);
    Sanctum::actingAs($this->ownerA);

    $this->getJson("/api/tasks/{$this->taskB->id}/summary")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/summary")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/summaries/{$this->draftB->id}/approve")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/summaries/{$this->draftB->id}/reject")->assertNotFound();

    expect(TaskSummary::withoutGlobalScopes()->find($this->draftB->id)->status)->toBe(TaskSummaryStatus::Draft)
        ->and(TaskSummary::withoutGlobalScopes()->where('task_id', $this->taskB->id)->count())->toBe(1);
    $this->ai->assertNothingSent();
});

it('never generates another organization\'s summary from a job dispatched for this one', function () {
    $pendingB = createSummary($this->orgB, inOrganization($this->orgB, fn () => Task::factory()->create(['description' => 'Org B secret'])), TaskSummaryStatus::Pending);

    GenerateTaskSummary::dispatch($this->orgA->id, $pendingB->id);
    (new GenerateTaskSummary($this->orgA->id, $pendingB->id))->failed(null);

    $this->ai->assertNothingSent();
    expect(TaskSummary::withoutGlobalScopes()->find($pendingB->id)->status)->toBe(TaskSummaryStatus::Pending)
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(0);
});

it('can\'t store a summary under another organization\'s task, even skipping the scope', function () {
    expect(fn () => createSummary($this->orgA, $this->taskB, TaskSummaryStatus::Draft))->toThrow(QueryException::class);
});

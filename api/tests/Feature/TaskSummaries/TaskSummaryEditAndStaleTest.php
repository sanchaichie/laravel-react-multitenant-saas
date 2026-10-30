<?php

use App\Ai\Prompts\TaskSummaryPrompt;
use App\Enums\AuditAction;
use App\Enums\TaskSummaryStatus;
use App\Jobs\GenerateTaskSummary;
use App\Models\Task;
use App\Models\TaskSummary;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
    $this->task = inOrganization($this->acme, fn () => Task::factory()->create([
        'title' => 'Plan the offsite',
        'description' => 'Book a venue and send the agenda.',
    ]));
    $this->draft = createSummary($this->acme, $this->task, TaskSummaryStatus::Draft, [
        'summary' => 'Organize the offsite.',
        'key_points' => ['Book a venue', 'Send the agenda'],
        'source_hash' => TaskSummaryPrompt::sourceHash($this->task),
    ]);
    Sanctum::actingAs($this->owner);
});

it('saves edits made before approving, and records that it was edited', function () {
    $this->postJson("/api/tasks/{$this->task->id}/summaries/{$this->draft->id}/approve", [
        'summary' => '  Organize the team offsite. ',
        'key_points' => ['Book a venue', 'Send the agenda', ' Order food '],
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.summary', 'Organize the team offsite.')
        ->assertJsonPath('data.key_points', ['Book a venue', 'Send the agenda', 'Order food']);

    expect(auditEntries($this->acme, AuditAction::SummaryApproved)->sole()->metadata)
        ->toEqual(['summary_id' => $this->draft->id, 'edited' => true]);
});

it('does not record an edit when the fields sent are unchanged', function () {
    $this->postJson("/api/tasks/{$this->task->id}/summaries/{$this->draft->id}/approve", [
        'summary' => 'Organize the offsite.',
        'key_points' => ['Book a venue', 'Send the agenda'],
    ])->assertOk();

    expect(auditEntries($this->acme, AuditAction::SummaryApproved)->sole()->metadata)
        ->toEqual(['summary_id' => $this->draft->id]);
});

it('keeps fields that were left out', function () {
    $this->postJson("/api/tasks/{$this->task->id}/summaries/{$this->draft->id}/approve", ['key_points' => []])
        ->assertOk()
        ->assertJsonPath('data.summary', 'Organize the offsite.')
        ->assertJsonPath('data.key_points', []);
});

it('validates edits with the same limits as the AI output', function (array $edits, string $field) {
    $this->postJson("/api/tasks/{$this->task->id}/summaries/{$this->draft->id}/approve", $edits)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(TaskSummary::withoutGlobalScopes()->find($this->draft->id))
        ->status->toBe(TaskSummaryStatus::Draft)
        ->summary->toBe('Organize the offsite.');
})->with([
    'blank summary' => [['summary' => '   '], 'summary'],
    'summary too long' => [['summary' => str_repeat('a', 601)], 'summary'],
    'too many key points' => [['key_points' => ['1', '2', '3', '4', '5', '6']], 'key_points'],
    'blank key point' => [['key_points' => ['ok', ' ']], 'key_points.1'],
    'key point too long' => [['key_points' => [str_repeat('b', 161)]], 'key_points.0'],
    'key points not a list' => [['key_points' => ['a' => 'x']], 'key_points'],
]);

it('ignores edits sent with a rejection', function () {
    $this->postJson("/api/tasks/{$this->task->id}/summaries/{$this->draft->id}/reject", ['summary' => 'Changed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.summary', 'Organize the offsite.');
});

it('only edits drafts', function () {
    $approved = createSummary($this->acme, $this->task, TaskSummaryStatus::Approved, ['summary' => 'Approved text']);

    $this->postJson("/api/tasks/{$this->task->id}/summaries/{$approved->id}/approve", ['summary' => 'Changed'])->assertStatus(409);

    expect(TaskSummary::withoutGlobalScopes()->find($approved->id)->summary)->toBe('Approved text');
});

it('flags a summary as stale once the task title or description changes', function (array $change, bool $stale) {
    $this->getJson("/api/tasks/{$this->task->id}/summary")->assertJsonPath('data.stale', false);

    $this->patchJson("/api/tasks/{$this->task->id}", $change)->assertOk();

    $this->getJson("/api/tasks/{$this->task->id}/summary")->assertJsonPath('data.stale', $stale);
})->with([
    'title' => [['title' => 'Plan the retreat'], true],
    'description' => [['description' => 'Something else.'], true],
    'status only' => [['status' => 'done'], false],
]);

it('hashes the task as it was sent to the model, not as it was requested', function () {
    $pending = createSummary($this->acme, $this->task, TaskSummaryStatus::Pending);
    // Edited between the request and the job run: the job sends (and hashes) the new text.
    inOrganization($this->acme, fn () => $this->task->update(['description' => 'Book a venue.']));

    dispatch(new GenerateTaskSummary($this->acme->id, $pending->id));

    $this->getJson("/api/tasks/{$this->task->id}/summary")
        ->assertJsonPath('data.id', $pending->id)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.stale', false);
});

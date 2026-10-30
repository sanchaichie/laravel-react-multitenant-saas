<?php

use App\Enums\AuditAction;
use App\Enums\TaskSummaryStatus;
use App\Models\Task;
use App\Models\TaskSummary;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization(name: 'Acme');
    $this->member = addMember($this->acme);
    $make = fn (array $attributes) => inOrganization($this->acme, fn () => Task::factory()->create($attributes));

    $this->withText = $make(['description' => 'Something to summarize.', 'created_by' => $this->member->id]);
    $this->noText = $make(['description' => null, 'created_by' => $this->member->id]);
    $this->blank = $make(['description' => '', 'created_by' => $this->member->id]);
    $this->summarized = $make(['description' => 'Already done.', 'created_by' => $this->owner->id]);
    createSummary($this->acme, $this->summarized, TaskSummaryStatus::Approved);
});

function summarizedTaskIds(): array
{
    return TaskSummary::withoutGlobalScopes()->where('status', '!=', 'approved')->orderBy('task_id')->pluck('task_id')->all();
}

it('queues summaries for tasks with a description and no summary', function () {
    $this->artisan('ai:summarize-tasks')
        ->expectsOutputToContain('Acme (#'.$this->acme->id.'): queued 1.')
        ->expectsOutputToContain('Queued 1 summaries.')
        ->assertSuccessful();

    expect(summarizedTaskIds())->toBe([$this->withText->id]);

    // Credited to the creator, logged as a system request.
    $summary = TaskSummary::withoutGlobalScopes()->where('task_id', $this->withText->id)->sole();
    $entry = auditEntries($this->acme, AuditAction::SummaryRequested)->sole();
    expect($summary->requested_by)->toBe($this->member->id)
        ->and($summary->status)->toBe(TaskSummaryStatus::Draft)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->metadata)->toEqual(['summary_id' => $summary->id, 'source' => 'backfill']);
});

it('only counts in a dry run', function () {
    $this->artisan('ai:summarize-tasks --dry-run')
        ->expectsOutputToContain('would queue 1.')
        ->expectsOutputToContain('Would queue 1 summaries.')
        ->assertSuccessful();

    expect(summarizedTaskIds())->toBe([]);
    $this->ai->assertNothingSent();
});

it('stops at the limit', function () {
    inOrganization($this->acme, fn () => Task::factory()->count(3)->create(['description' => 'More.', 'created_by' => $this->owner->id]));

    $this->artisan('ai:summarize-tasks --limit=2')->assertSuccessful();

    expect(summarizedTaskIds())->toHaveCount(2);
});

it('skips tasks whose creator left, can no longer edit them or isn\'t verified', function () {
    $viewer = addMember($this->acme, createRole($this->acme, 'Viewer'));
    $leftUser = addMember($this->acme);
    $unverified = addMember($this->acme, attributes: ['email_verified_at' => null]);
    inOrganization($this->acme, fn () => Task::factory()->create(['description' => 'By a viewer.', 'created_by' => $viewer->id]));
    inOrganization($this->acme, fn () => Task::factory()->create(['description' => 'By an unverified member.', 'created_by' => $unverified->id]));
    inOrganization($this->acme, fn () => Task::factory()->create(['description' => 'By someone who left.', 'created_by' => $leftUser->id]));
    $this->acme->members()->detach($leftUser);

    $this->artisan('ai:summarize-tasks')
        ->expectsOutputToContain("queued 1, skipped 3 whose creator left, can't edit them or isn't verified.")
        ->assertSuccessful();

    expect(summarizedTaskIds())->toBe([$this->withText->id]);
});

it('only touches the organizations asked for', function () {
    [$otherOwner, $other] = userInOrganization(name: 'Other');
    $otherTask = inOrganization($other, fn () => Task::factory()->create(['description' => 'Other org.', 'created_by' => $otherOwner->id]));

    $this->artisan('ai:summarize-tasks', ['--organization' => [$other->id]])->assertSuccessful();

    expect(summarizedTaskIds())->toBe([$otherTask->id])
        ->and(TaskSummary::withoutGlobalScopes()->where('task_id', $otherTask->id)->sole()->organization_id)->toBe($other->id);
});

it('refuses a limit below 1', function () {
    $this->artisan('ai:summarize-tasks --limit=0')->assertFailed();
});

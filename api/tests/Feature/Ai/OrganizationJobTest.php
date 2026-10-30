<?php

use App\Jobs\OrganizationJob;
use App\Models\Task;
use App\Support\CurrentOrganization;

/**
 * Records what it could see while it ran.
 */
class ProbeOrganizationJob extends OrganizationJob
{
    /** @var array{organization: int|null, tasks: list<string>}|null */
    public static ?array $seen = null;

    public function handle(CurrentOrganization $current): void
    {
        static::$seen = [
            'organization' => $current->id(),
            'tasks' => Task::query()->orderBy('title')->pluck('title')->all(),
        ];
    }
}

beforeEach(fn () => ProbeOrganizationJob::$seen = null);

it('runs inside its organization, so scoped queries only see that organization', function () {
    [, $orgA] = userInOrganization(name: 'Org A');
    [, $orgB] = userInOrganization(name: 'Org B');
    inOrganization($orgA, fn () => Task::factory()->create(['title' => 'A1']));
    inOrganization($orgB, fn () => Task::factory()->create(['title' => 'B1']));

    ProbeOrganizationJob::dispatch($orgB->id);

    expect(ProbeOrganizationJob::$seen)->toBe(['organization' => $orgB->id, 'tasks' => ['B1']])
        // Reset afterwards, so nothing leaks into the next job or the request.
        ->and(app(CurrentOrganization::class)->id())->toBeNull();
});

it('does nothing when the organization was deleted after dispatch', function () {
    [, $orgA] = userInOrganization();
    $job = new ProbeOrganizationJob($orgA->id);
    $orgA->delete();

    dispatch($job);

    expect(ProbeOrganizationJob::$seen)->toBeNull();
});

it('keeps the HTTP timeout below the job timeout, and that below the queue retry_after', function () {
    $job = new ProbeOrganizationJob(1);

    expect(config('ai.timeout'))->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
});

it('restores the caller\'s organization after running synchronously', function () {
    [, $orgA] = userInOrganization(name: 'Org A');
    [, $orgB] = userInOrganization(name: 'Org B');

    inOrganization($orgA, function () use ($orgA, $orgB) {
        ProbeOrganizationJob::dispatch($orgB->id);

        expect(ProbeOrganizationJob::$seen['organization'])->toBe($orgB->id)
            ->and(app(CurrentOrganization::class)->id())->toBe($orgA->id);
    });
});

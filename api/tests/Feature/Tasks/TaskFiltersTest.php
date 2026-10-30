<?php

use App\Enums\DefaultRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner);
    $this->member = addMember($this->organization);
    Sanctum::actingAs($this->owner);
});

/** @param  array<string, mixed>  $attributes */
function task(array $attributes = []): Task
{
    return inOrganization(test()->organization, fn () => Task::factory()->create(['description' => null, ...$attributes]));
}

/** The titles the list returns for a query string. */
function listed(string $query = ''): array
{
    return collect(test()->getJson('/api/tasks'.($query ? "?{$query}" : ''))->assertOk()->json('data'))->pluck('title')->all();
}

it('filters by status and priority, alone and combined', function () {
    task(['title' => 'todo-low', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Low]);
    task(['title' => 'done-high', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High]);
    task(['title' => 'todo-high', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High]);

    expect(listed('status=done'))->toBe(['done-high'])
        ->and(listed('priority=high&sort=due'))->toBe(['done-high', 'todo-high'])
        ->and(listed('status=todo&priority=high'))->toBe(['todo-high']);
});

it('filters by assignee: an id, me, or none', function () {
    task(['title' => 'mine', 'assignee_id' => $this->owner->id]);
    task(['title' => 'theirs', 'assignee_id' => $this->member->id]);
    task(['title' => 'nobody']);

    expect(listed('assignee=me'))->toBe(['mine'])
        ->and(listed("assignee={$this->member->id}"))->toBe(['theirs'])
        ->and(listed('assignee=none'))->toBe(['nobody'])
        ->and(listed('assignee=999999'))->toBe([]);
});

it('filters overdue: due before today and not done', function () {
    task(['title' => 'late', 'due_date' => today()->subDay()->toDateString()]);
    task(['title' => 'late but done', 'due_date' => today()->subDay()->toDateString(), 'status' => TaskStatus::Done]);
    task(['title' => 'today', 'due_date' => today()->toDateString()]);
    task(['title' => 'no date']);

    expect(listed('overdue=1'))->toBe(['late'])
        ->and(listed('overdue=0'))->toHaveCount(4);
});

it('searches title and description, case-insensitively', function () {
    task(['title' => 'Plan the Launch']);
    task(['title' => 'Other', 'description' => 'before the LAUNCH party']);
    task(['title' => 'Unrelated']);

    expect(listed('search=launch&sort=due'))->toBe(['Plan the Launch', 'Other']);
});

it('matches % and _ literally in a search', function () {
    task(['title' => '100% done']);
    task(['title' => '1000 done']);
    task(['title' => 'snake_case']);
    task(['title' => 'snakeXcase']);

    expect(listed('search='.urlencode('0%')))->toBe(['100% done'])
        ->and(listed('search=e_c'))->toBe(['snake_case']);
});

it('sorts by newest, due date or priority, with ties broken by id', function () {
    $this->travelTo(now()->subHour());
    $a = task(['title' => 'a', 'due_date' => '2030-03-01', 'priority' => TaskPriority::Low]);
    $this->travelBack();
    $b = task(['title' => 'b', 'priority' => TaskPriority::High]);
    $c = task(['title' => 'c', 'due_date' => '2030-01-01', 'priority' => TaskPriority::Medium]);
    $d = task(['title' => 'd', 'due_date' => '2030-01-01', 'priority' => TaskPriority::High]);

    expect(listed())->toBe(['d', 'c', 'b', 'a'])
        ->and(listed('sort=newest'))->toBe(['d', 'c', 'b', 'a'])
        ->and(listed('sort=due'))->toBe(['c', 'd', 'a', 'b'])
        ->and(listed('sort=priority'))->toBe(['d', 'b', 'c', 'a']);
});

it('paginates 20 a page and keeps the query string in the links', function () {
    inOrganization($this->organization, fn () => Task::factory()->count(25)->create(['priority' => TaskPriority::High]));
    task(['priority' => TaskPriority::Low]);

    $this->getJson('/api/tasks?priority=high&page=2')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('links.prev', fn (string $url) => str_contains($url, 'priority=high'));

    $this->getJson('/api/tasks?page=9')->assertOk()->assertJsonCount(0, 'data');
});

it('lists without N+1 queries', function () {
    inOrganization($this->organization, fn () => Task::factory()->count(10)->createdBy($this->owner)->assignedTo($this->member)->create());

    DB::enableQueryLog();
    $this->getJson('/api/tasks')->assertOk();
    $taskQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"users"') || str_contains($q['query'], '"tasks"'));

    // Count, page, assignees, creators (plus the auth user lookups, which don't grow).
    expect($taskQueries->count())->toBeLessThan(8);
});

it('refuses unknown filter values', function (string $query, string $field) {
    $this->getJson("/api/tasks?{$query}")->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['status=blocked', 'status'],
    ['priority=urgent', 'priority'],
    ['assignee=someone', 'assignee'],
    ['assignee=-1', 'assignee'],
    ['overdue=maybe', 'overdue'],
    ['sort=title', 'sort'],
    ['page=0', 'page'],
    ['search[]=x', 'search'],
    ['search='.str_repeat('a', 101), 'search'],
]);

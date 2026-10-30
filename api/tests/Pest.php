<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\TaskSummaryStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSummary;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * A user who is a member of a new organization (with the given role) and has it as current.
 * The organization has its default roles; a Role must be one of its own.
 *
 * @return array{0: User, 1: Organization}
 */
function userInOrganization(DefaultRole $role = DefaultRole::Owner, string $name = 'Acme'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user, $role)->create(['name' => $name]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return [$user, $organization];
}

/**
 * Add a new user to the organization with the given role (it becomes their current one).
 *
 * @param  array<string, mixed>  $attributes
 */
function addMember(Organization $organization, DefaultRole|Role $role = DefaultRole::Member, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $organization->members()->attach($user, ['role_id' => $organization->role($role)->id]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return $user;
}

/**
 * An invitation written directly (no email). Returns it and its plain token.
 *
 * @param  array<string, mixed>  $attributes  any column, e.g. expires_at or accepted_at
 * @return array{0: OrganizationInvitation, 1: string}
 */
function createInvitation(Organization $organization, string $email, DefaultRole|Role $role = DefaultRole::Member, array $attributes = []): array
{
    $token = Str::random(48);

    $roleId = $organization->role($role)->id;

    $invitation = inOrganization($organization, function () use ($email, $roleId, $token, $attributes) {
        $invitation = (new OrganizationInvitation)->forceFill(array_merge([
            'email' => $email,
            'role_id' => $roleId,
            'token_hash' => OrganizationInvitation::hashToken($token),
            'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
        ], $attributes));
        $invitation->save();

        return $invitation;
    });

    return [$invitation, $token];
}

/**
 * Run code acting in an organization (no logged-in user needed), e.g. to create
 * tenant-owned rows. Returns what the callback returns.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inOrganization(Organization $organization, Closure $callback): mixed
{
    return app(CurrentOrganization::class)->run($organization, $callback);
}

/**
 * A custom role in the organization (created as its owner would, without the API).
 *
 * @param  list<Permission>  $permissions
 */
function createRole(Organization $organization, string $name, array $permissions = []): Role
{
    return inOrganization($organization, fn () => Role::factory()->create(['name' => $name, 'permissions' => $permissions]));
}

/**
 * The id of a default role in the organization (by default, the logged-in user's
 * current one), to send as role_id.
 */
function roleId(DefaultRole $role, ?Organization $organization = null): int
{
    $organization ??= Organization::query()->findOrFail(auth()->user()->current_organization_id);

    return $organization->role($role)->id;
}

/**
 * Audit log entries of an organization (any, by default), oldest first, optionally
 * only one action.
 *
 * @return EloquentCollection<int, AuditLog>
 */
function auditEntries(?Organization $organization = null, ?AuditAction $action = null): EloquentCollection
{
    return AuditLog::withoutGlobalScopes()
        ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
        ->when($action, fn ($query) => $query->where('action', $action))
        ->orderBy('id')
        ->get();
}

/**
 * A task summary written directly (no AI call), e.g. a draft to review.
 *
 * @param  array<string, mixed>  $attributes  any column
 */
function createSummary(Organization $organization, Task $task, TaskSummaryStatus $status, array $attributes = []): TaskSummary
{
    return inOrganization($organization, function () use ($task, $status, $attributes) {
        $summary = (new TaskSummary)->forceFill(array_merge([
            'task_id' => $task->id,
            'status' => $status,
            'summary' => $status === TaskSummaryStatus::Pending ? null : 'A summary.',
            'key_points' => $status === TaskSummaryStatus::Pending ? null : ['A point'],
        ], $attributes));
        $summary->save();

        return $summary;
    });
}

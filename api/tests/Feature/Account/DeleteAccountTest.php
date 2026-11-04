<?php

use App\Enums\AuditAction;
use App\Enums\ChatMessageRole;
use App\Enums\DefaultRole;
use App\Enums\TaskSummaryStatus;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Models\TaskChatMessage;
use App\Models\TaskSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

it('needs the password', function () {
    [$user] = userInOrganization();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

it('deletes the user, their tokens, and organizations where they are the only member', function () {
    [$user, $organization] = userInOrganization(name: 'Solo');
    $token = $user->createToken('api')->plainTextToken;
    $task = inOrganization($organization, fn () => Task::factory()->create());

    $this->withToken($token)->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(User::find($user->id))->toBeNull()
        ->and(Organization::find($organization->id))->toBeNull()
        ->and(Task::withoutGlobalScope(OrganizationScope::class)->find($task->id))->toBeNull()
        ->and(Role::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organization->id)->exists())->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});

it('leaves organizations that have another owner, audited there, and keeps their data', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->withMember($owner, DefaultRole::Owner)->create(['name' => 'Shared']);
    $user = addMember($organization, DefaultRole::Owner);
    $task = inOrganization($organization, fn () => Task::factory()->create(['created_by' => $user->id]));
    createChatMessage($organization, $task, $user, ChatMessageRole::User, 'My question');

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(User::find($user->id))->toBeNull()
        ->and(Organization::find($organization->id))->not->toBeNull()
        ->and($organization->members()->pluck('users.id')->all())->toBe([$owner->id])
        ->and(Task::withoutGlobalScope(OrganizationScope::class)->find($task->id)->created_by)->toBeNull()
        ->and(TaskChatMessage::withoutGlobalScope(OrganizationScope::class)->where('user_id', $user->id)->exists())->toBeFalse();

    $entry = auditEntries($organization)->last();
    expect($entry->action)->toBe(AuditAction::MemberRemoved)
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->actor_id)->toBeNull();
});

it('leaves organizations where they are not an owner', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->withMember($owner, DefaultRole::Owner)->create();
    $user = addMember($organization, DefaultRole::Admin);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(Organization::find($organization->id))->not->toBeNull()
        ->and($organization->members()->pluck('users.id')->all())->toBe([$owner->id]);
});

it('is refused while they are the last owner of an organization with other members', function () {
    [$user, $organization] = userInOrganization(name: 'Acme');
    addMember($organization, DefaultRole::Admin);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account'])
        ->assertJsonPath('errors.account.0', fn (string $message) => $message === "You're the last owner of Acme, which has other members. Make one of them Owner first.");

    expect(User::find($user->id))->not->toBeNull()
        ->and($organization->members()->count())->toBe(2);
});

it('is refused while they are the only member of an organization Stripe still bills', function () {
    [$user, $solo] = userInOrganization(name: 'Solo');
    subscribe($solo);
    $acme = Organization::factory()->withMember($user, DefaultRole::Owner)->create(['name' => 'Acme']);
    addMember($acme);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', "You're the last owner of Acme, which has other members. Make one of them Owner first. Solo still has a paid plan. Cancel it in Billing first.");

    expect(User::find($user->id))->not->toBeNull()
        ->and(Organization::find($solo->id))->not->toBeNull();
});

it('deletes an organization whose plan was canceled at the period end, since it won\'t renew', function () {
    [$user, $solo] = userInOrganization(name: 'Solo');
    subscribe($solo, ['ends_at' => now()->addWeek()]);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(Organization::find($solo->id))->toBeNull();
});

it('deletes an organization whose plan was canceled and has ended', function () {
    [$user, $solo] = userInOrganization(name: 'Solo');
    subscribe($solo, ['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(Organization::find($solo->id))->toBeNull()
        ->and(DB::table('subscriptions')->where('organization_id', $solo->id)->exists())->toBeFalse();
});

it('lists every blocking organization and changes nothing anywhere', function () {
    [$user, $acme] = userInOrganization(name: 'Acme');
    addMember($acme);
    $beta = Organization::factory()->withMember($user, DefaultRole::Owner)->create(['name' => 'Beta']);
    addMember($beta);
    $solo = Organization::factory()->withMember($user, DefaultRole::Owner)->create(['name' => 'Solo']);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/account', ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.account.0', "You're the last owner of Acme and Beta, which have other members. Make one of them Owner in each first.");

    expect(Organization::find($solo->id))->not->toBeNull()
        ->and($user->organizations()->count())->toBe(3);
});

it("never touches another organization's data", function () {
    [$user, $mine] = userInOrganization(name: 'Mine');
    [$other, $theirs] = userInOrganization(name: 'Theirs');
    $theirTask = inOrganization($theirs, fn () => Task::factory()->create());
    $auditCount = AuditLog::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $theirs->id)->count();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(Organization::find($mine->id))->toBeNull()
        ->and(Organization::find($theirs->id))->not->toBeNull()
        ->and($theirs->members()->pluck('users.id')->all())->toBe([$other->id])
        ->and(Task::withoutGlobalScope(OrganizationScope::class)->find($theirTask->id))->not->toBeNull()
        ->and(AuditLog::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $theirs->id)->count())->toBe($auditCount);
});

it('removes their pending password reset tokens', function () {
    [$user] = userInOrganization();
    Password::broker()->createToken($user);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

it('takes a sole-member organization\'s AI data and chats with it, and unassigns their tasks where they leave', function () {
    [$user, $solo] = userInOrganization(name: 'Solo');
    $soloTask = inOrganization($solo, fn () => Task::factory()->create(['created_by' => $user->id]));
    createSummary($solo, $soloTask, TaskSummaryStatus::Draft);
    createChatMessage($solo, $soloTask, $user, ChatMessageRole::User, 'Solo question');
    recordAiUsage($solo, 0.5, ['user_id' => $user->id]);

    $owner = User::factory()->create();
    $shared = Organization::factory()->withMember($owner, DefaultRole::Owner)->create(['name' => 'Shared']);
    $shared->members()->attach($user, ['role_id' => $shared->role(DefaultRole::Member)->id]);
    $assigned = inOrganization($shared, fn () => Task::factory()->create(['created_by' => $owner->id, 'assignee_id' => $user->id]));
    recordAiUsage($shared, 0.25, ['user_id' => $user->id]);

    Sanctum::actingAs($user);
    $this->deleteJson('/api/account', ['password' => 'password'])->assertNoContent();

    $all = fn (string $model) => $model::withoutGlobalScope(OrganizationScope::class);
    expect($all(TaskSummary::class)->where('organization_id', $solo->id)->exists())->toBeFalse()
        ->and($all(TaskChatMessage::class)->exists())->toBeFalse()
        ->and($all(AiUsage::class)->where('organization_id', $solo->id)->exists())->toBeFalse()
        // The shared organization keeps its usage (now without a person) and the task, unassigned.
        ->and($all(AiUsage::class)->where('organization_id', $shared->id)->sole()->user_id)->toBeNull()
        ->and($all(Task::class)->find($assigned->id)->assignee_id)->toBeNull()
        // The removal is recorded in the organization they left (not their current one), and only there.
        ->and(auditEntries($shared, AuditAction::MemberRemoved))->toHaveCount(1)
        ->and($all(AuditLog::class)->where('action', AuditAction::MemberRemoved)->count())->toBe(1);
});

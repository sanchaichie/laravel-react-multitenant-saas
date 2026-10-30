<?php

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\InviteMember;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

// Who may manage roles, and the no-escalation rules: you only grant what you have,
// and only change roles that can do strictly less than yours.

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner);
    $this->admin = $this->organization->role(DefaultRole::Admin);
    $this->member = $this->organization->role(DefaultRole::Member);
});

/** Someone with a role that has roles.manage plus $extra. */
function roleManager(array $extra = []): Role
{
    $role = createRole(test()->organization, 'Role manager', [Permission::RolesManage, ...$extra]);
    Sanctum::actingAs(addMember(test()->organization, $role));

    return $role;
}

it('lets every member see the roles', function (DefaultRole $role) {
    Sanctum::actingAs($role === DefaultRole::Owner ? $this->owner : addMember($this->organization, $role));

    $this->getJson('/api/roles')->assertOk();
    $this->getJson('/api/permissions')->assertOk();
})->with(DefaultRole::cases());

it('needs roles.manage to create, edit or delete roles', function (DefaultRole $role) {
    Sanctum::actingAs(addMember($this->organization, $role));
    $viewer = createRole($this->organization, 'Viewer');

    $this->postJson('/api/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();
    $this->patchJson("/api/roles/{$viewer->id}", ['name' => 'X', 'permissions' => []])->assertForbidden();
    $this->deleteJson("/api/roles/{$viewer->id}")->assertForbidden();
    // Forbidden before validation: no field errors for someone who can't do it at all.
    $this->postJson('/api/roles', [])->assertForbidden();
})->with([DefaultRole::Admin, DefaultRole::Member]);

it('never lets anyone edit or delete the owner role', function () {
    Sanctum::actingAs($this->owner);
    $ownerRole = $this->organization->role(DefaultRole::Owner);

    $this->patchJson("/api/roles/{$ownerRole->id}", ['name' => 'Boss', 'permissions' => []])->assertForbidden();
    $this->deleteJson("/api/roles/{$ownerRole->id}")->assertForbidden();

    expect($ownerRole->fresh()->name)->toBe('Owner');
});

it('lets a role manager grant only permissions they have', function () {
    roleManager([Permission::TasksCreate, Permission::AiUse]);

    $this->postJson('/api/roles', ['name' => 'Writer', 'permissions' => ['tasks.create']])->assertCreated();
    $this->postJson('/api/roles', ['name' => 'Inviter', 'permissions' => ['members.invite']])->assertForbidden();
    $this->patchJson("/api/roles/{$this->member->id}", ['name' => 'Member', 'permissions' => ['tasks.manage']])->assertForbidden();

    expect(Role::query()->where('name', 'Inviter')->exists())->toBeFalse()
        ->and($this->member->fresh()->permissions->all())->toBe([Permission::TasksCreate, Permission::AiUse]);
});

it('lets a role manager change only roles that can do strictly less', function () {
    $mine = roleManager([Permission::TasksCreate, Permission::AiUse]);
    $peer = createRole($this->organization, 'Peer', [Permission::RolesManage, Permission::TasksCreate, Permission::AiUse]);
    $viewer = createRole($this->organization, 'Viewer');

    // Member (tasks.create, ai.use) and Viewer (nothing) can do less.
    $this->patchJson("/api/roles/{$this->member->id}", ['name' => 'Member', 'permissions' => []])->assertOk();
    $this->deleteJson("/api/roles/{$viewer->id}")->assertNoContent();

    // Not their own, not a peer's, not Admin's (which has what they lack).
    foreach ([$mine, $peer, $this->admin] as $role) {
        $this->patchJson("/api/roles/{$role->id}", ['name' => $role->name, 'permissions' => []])->assertForbidden();
        $this->deleteJson("/api/roles/{$role->id}")->assertForbidden();
    }
});

it('tells the app which roles the requesting user may change', function () {
    roleManager([Permission::TasksCreate, Permission::AiUse]);

    $can = collect($this->getJson('/api/roles')->assertOk()->json('data'))->pluck('can', 'name');

    expect($can['Owner'])->toBe(['update' => false, 'delete' => false])
        ->and($can['Admin'])->toBe(['update' => false, 'delete' => false])
        ->and($can['Role manager'])->toBe(['update' => false, 'delete' => false])
        ->and($can['Member'])->toBe(['update' => true, 'delete' => true]);
});

it('lets a custom role assign and invite only roles it includes', function () {
    Mail::fake();
    $lead = createRole($this->organization, 'Lead', [Permission::TasksCreate, Permission::AiUse, Permission::MembersInvite, Permission::MembersManage]);
    Sanctum::actingAs(addMember($this->organization, $lead));
    $ana = addMember($this->organization);

    $this->patchJson("/api/members/{$ana->id}", ['role_id' => $lead->id])->assertOk();
    $this->patchJson("/api/members/{$ana->id}", ['role_id' => $this->admin->id])->assertForbidden();
    $this->postJson('/api/invitations', ['email' => 'x@example.com', 'role_id' => $this->member->id])->assertCreated();
    $this->postJson('/api/invitations', ['email' => 'y@example.com', 'role_id' => $this->admin->id])->assertForbidden();
});

it('lets a viewer read tasks but not write them, even one assigned to them', function () {
    $viewer = createRole($this->organization, 'Viewer');
    $user = addMember($this->organization, $viewer);
    $task = inOrganization($this->organization, fn () => Task::factory()->assignedTo($user)->createdBy($user)->create());
    Sanctum::actingAs($user);

    $this->getJson('/api/tasks')->assertOk()->assertJsonPath('data.0.can', ['update' => false, 'delete' => false, 'write_with_ai' => false]);
    $this->getJson("/api/tasks/{$task->id}")->assertOk();
    $this->postJson('/api/tasks', ['title' => 'Nope'])->assertForbidden();
    $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])->assertForbidden();
    $this->deleteJson("/api/tasks/{$task->id}")->assertForbidden();
    $this->getJson('/api/me')->assertJsonPath('data.current_organization.permissions', []);
});

it('applies a role\'s new permissions on the next request', function () {
    $user = addMember($this->organization);
    Sanctum::actingAs($user);
    $this->postJson('/api/tasks', ['title' => 'Allowed'])->assertCreated();

    $this->member->update(['permissions' => []]);

    $this->postJson('/api/tasks', ['title' => 'Not any more'])->assertForbidden();
});

it('refuses to give a role widened after the policy check', function () {
    Mail::fake();
    $lead = createRole($this->organization, 'Lead', [Permission::TasksCreate, Permission::MembersInvite, Permission::MembersManage]);
    $actor = addMember($this->organization, $lead);
    $ana = addMember($this->organization);
    Sanctum::actingAs($actor);
    $viewer = createRole($this->organization, 'Viewer');
    // As if an owner widened Viewer between the policy check and the lock.
    $stale = clone $viewer;
    $viewer->update(['permissions' => [Permission::TasksManage]]);
    $this->getJson('/api/me')->assertOk(); // resolves the actor's organization and role for this test
    $organization = app(CurrentOrganization::class)->get();

    expect(fn () => app(ChangeMemberRole::class)->handle($organization, $organization->members()->findOrFail($ana->id), $stale))
        ->toThrow(ValidationException::class);
    expect(fn () => app(InviteMember::class)->handle($actor, $organization, 'x@example.com', $stale))
        ->toThrow(ValidationException::class);
});

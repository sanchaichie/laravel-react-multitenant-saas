<?php

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

// The permission table of plan 06, as datasets: actor role × target role.

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner);
});

/** A user acting with $role in the organization. */
function actor(DefaultRole $role): User
{
    $user = $role === DefaultRole::Owner ? test()->owner : addMember(test()->organization, $role);
    Sanctum::actingAs($user);

    return $user;
}

it('gives new organizations the default roles, and knows which roles include which', function () {
    [$owner, $admin, $member] = array_map(fn (DefaultRole $role) => $this->organization->role($role), DefaultRole::cases());

    expect($owner->grants()->all())->toBe(Permission::cases())
        ->and($admin->grants()->all())->toBe([Permission::TasksCreate, Permission::TasksManage, Permission::MembersInvite, Permission::MembersManage, Permission::AuditLogView])
        ->and($member->grants()->all())->toBe([Permission::TasksCreate])
        ->and($owner->includes($admin))->toBeTrue()
        ->and($admin->includes($admin))->toBeTrue()
        ->and($admin->includesStrictly($admin))->toBeFalse()
        ->and($admin->includesStrictly($member))->toBeTrue()
        ->and($admin->includes($owner))->toBeFalse()
        ->and($member->includes($admin))->toBeFalse()
        ->and($member->includes($member))->toBeTrue();
});

it('lets every member see the members', function (DefaultRole $role) {
    actor($role);

    $this->getJson('/api/members')->assertOk();
})->with(DefaultRole::cases());

it('shows and revokes pending invitations only with members.invite', function (DefaultRole $role, bool $allowed) {
    [$invitation] = createInvitation($this->organization, 'new@example.com');
    actor($role);

    $this->getJson('/api/invitations')->assertStatus($allowed ? 200 : 403);
    $this->deleteJson("/api/invitations/{$invitation->id}")->assertStatus($allowed ? 204 : 403);
})->with([
    'owner' => [DefaultRole::Owner, true],
    'admin' => [DefaultRole::Admin, true],
    'member' => [DefaultRole::Member, false],
]);

it('invites only with roles the inviter includes', function (DefaultRole $actorRole, DefaultRole $invitedAs, bool $allowed) {
    actor($actorRole);

    $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => roleId($invitedAs)])
        ->assertStatus($allowed ? 201 : 403);

    expect(OrganizationInvitation::withoutGlobalScopes()->count())->toBe($allowed ? 1 : 0);
})->with([
    'owner invites owner' => [DefaultRole::Owner, DefaultRole::Owner, true],
    'owner invites admin' => [DefaultRole::Owner, DefaultRole::Admin, true],
    'admin invites admin' => [DefaultRole::Admin, DefaultRole::Admin, true],
    'admin invites member' => [DefaultRole::Admin, DefaultRole::Member, true],
    'admin invites owner' => [DefaultRole::Admin, DefaultRole::Owner, false],
    'member invites member' => [DefaultRole::Member, DefaultRole::Member, false],
]);

it('changes roles only between roles the actor includes', function (DefaultRole $actorRole, DefaultRole $targetRole, DefaultRole $newRole, bool $allowed) {
    actor($actorRole);
    $target = addMember($this->organization, $targetRole);

    $this->patchJson("/api/members/{$target->id}", ['role_id' => roleId($newRole)])->assertStatus($allowed ? 200 : 403);

    expect($this->organization->members()->findOrFail($target->id)->membership->role->name)->toBe(($allowed ? $newRole : $targetRole)->label());
})->with([
    'owner: admin → owner' => [DefaultRole::Owner, DefaultRole::Admin, DefaultRole::Owner, true],
    'owner: owner → member' => [DefaultRole::Owner, DefaultRole::Owner, DefaultRole::Member, true],
    'admin: member → admin' => [DefaultRole::Admin, DefaultRole::Member, DefaultRole::Admin, true],
    'admin: admin → member' => [DefaultRole::Admin, DefaultRole::Admin, DefaultRole::Member, true],
    'admin: member → owner' => [DefaultRole::Admin, DefaultRole::Member, DefaultRole::Owner, false],
    'admin: owner → member' => [DefaultRole::Admin, DefaultRole::Owner, DefaultRole::Member, false],
    'member: member → admin' => [DefaultRole::Member, DefaultRole::Member, DefaultRole::Admin, false],
]);

it('removes others only when the actor includes their role', function (DefaultRole $actorRole, DefaultRole $targetRole, bool $allowed) {
    actor($actorRole);
    $target = addMember($this->organization, $targetRole);

    $this->deleteJson("/api/members/{$target->id}")->assertStatus($allowed ? 204 : 403);

    expect($target->isMemberOf($this->organization))->toBe(! $allowed);
})->with([
    'owner removes owner' => [DefaultRole::Owner, DefaultRole::Owner, true],
    'owner removes admin' => [DefaultRole::Owner, DefaultRole::Admin, true],
    'admin removes admin' => [DefaultRole::Admin, DefaultRole::Admin, true],
    'admin removes member' => [DefaultRole::Admin, DefaultRole::Member, true],
    'admin removes owner' => [DefaultRole::Admin, DefaultRole::Owner, false],
    'member removes member' => [DefaultRole::Member, DefaultRole::Member, false],
]);

it('lets anyone leave', function (DefaultRole $role) {
    $user = actor($role === DefaultRole::Owner ? DefaultRole::Admin : $role);

    $this->deleteJson("/api/members/{$user->id}")->assertNoContent();

    expect($user->isMemberOf($this->organization))->toBeFalse();
})->with([DefaultRole::Admin, DefaultRole::Member]);

it('tells the app what each member\'s row allows', function () {
    $admin = actor(DefaultRole::Admin);
    $member = addMember($this->organization, DefaultRole::Member);

    $rows = collect($this->getJson('/api/members')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->owner->id]['can'])->toBe(['update' => false, 'delete' => false])
        ->and($rows[$this->owner->id]['assignable_roles'])->toBe([])
        ->and($rows[$member->id]['can'])->toBe(['update' => true, 'delete' => true])
        ->and(array_column($rows[$member->id]['assignable_roles'], 'name'))->toBe(['Admin'])
        ->and($rows[$admin->id]['is_me'])->toBeTrue()
        ->and($rows[$admin->id]['can']['delete'])->toBeTrue(); // leaving
});

it('keeps at least one owner', function () {
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$this->owner->id}", ['role_id' => roleId(DefaultRole::Admin)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.role_id.0', 'An organization needs at least one owner. Make someone else an owner first.');
    $this->deleteJson("/api/members/{$this->owner->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['member']);

    expect($this->owner->isMemberOf($this->organization))->toBeTrue();
});

it('lets an owner step down once there is another owner', function () {
    $other = addMember($this->organization, DefaultRole::Owner);
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$this->owner->id}", ['role_id' => roleId(DefaultRole::Admin)])->assertOk()->assertJsonPath('data.role.name', 'Admin');

    // Now the other owner is the last one.
    Sanctum::actingAs($other);
    $this->deleteJson("/api/members/{$other->id}")->assertUnprocessable();
});

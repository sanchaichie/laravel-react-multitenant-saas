<?php

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

// The permission table of plan 06, as datasets: actor role × target role.

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->organization] = userInOrganization(OrganizationRole::Owner);
});

/** A user acting with $role in the organization. */
function actor(OrganizationRole $role): User
{
    $user = $role === OrganizationRole::Owner ? test()->owner : addMember(test()->organization, $role);
    Sanctum::actingAs($user);

    return $user;
}

it('maps roles to permissions, and knows which roles include which', function () {
    expect(OrganizationRole::Owner->grants())->toBe(Permission::cases())
        ->and(OrganizationRole::Admin->grants())->toBe([Permission::MembersInvite, Permission::MembersManage])
        ->and(OrganizationRole::Member->grants())->toBe([])
        ->and(OrganizationRole::Owner->includes(OrganizationRole::Admin))->toBeTrue()
        ->and(OrganizationRole::Admin->includes(OrganizationRole::Admin))->toBeTrue()
        ->and(OrganizationRole::Admin->includes(OrganizationRole::Owner))->toBeFalse()
        ->and(OrganizationRole::Member->includes(OrganizationRole::Admin))->toBeFalse()
        ->and(OrganizationRole::Member->includes(OrganizationRole::Member))->toBeTrue();
});

it('lets every member see the members', function (OrganizationRole $role) {
    actor($role);

    $this->getJson('/api/members')->assertOk();
})->with(OrganizationRole::cases());

it('shows and revokes pending invitations only with members.invite', function (OrganizationRole $role, bool $allowed) {
    [$invitation] = createInvitation($this->organization, 'new@example.com');
    actor($role);

    $this->getJson('/api/invitations')->assertStatus($allowed ? 200 : 403);
    $this->deleteJson("/api/invitations/{$invitation->id}")->assertStatus($allowed ? 204 : 403);
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'admin' => [OrganizationRole::Admin, true],
    'member' => [OrganizationRole::Member, false],
]);

it('invites only with roles the inviter includes', function (OrganizationRole $actorRole, OrganizationRole $invitedAs, bool $allowed) {
    actor($actorRole);

    $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role' => $invitedAs->value])
        ->assertStatus($allowed ? 201 : 403);

    expect(OrganizationInvitation::withoutGlobalScopes()->count())->toBe($allowed ? 1 : 0);
})->with([
    'owner invites owner' => [OrganizationRole::Owner, OrganizationRole::Owner, true],
    'owner invites admin' => [OrganizationRole::Owner, OrganizationRole::Admin, true],
    'admin invites admin' => [OrganizationRole::Admin, OrganizationRole::Admin, true],
    'admin invites member' => [OrganizationRole::Admin, OrganizationRole::Member, true],
    'admin invites owner' => [OrganizationRole::Admin, OrganizationRole::Owner, false],
    'member invites member' => [OrganizationRole::Member, OrganizationRole::Member, false],
]);

it('changes roles only between roles the actor includes', function (OrganizationRole $actorRole, OrganizationRole $targetRole, OrganizationRole $newRole, bool $allowed) {
    actor($actorRole);
    $target = addMember($this->organization, $targetRole);

    $this->patchJson("/api/members/{$target->id}", ['role' => $newRole->value])->assertStatus($allowed ? 200 : 403);

    expect($this->organization->members()->findOrFail($target->id)->membership->role)->toBe($allowed ? $newRole : $targetRole);
})->with([
    'owner: admin → owner' => [OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Owner, true],
    'owner: owner → member' => [OrganizationRole::Owner, OrganizationRole::Owner, OrganizationRole::Member, true],
    'admin: member → admin' => [OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Admin, true],
    'admin: admin → member' => [OrganizationRole::Admin, OrganizationRole::Admin, OrganizationRole::Member, true],
    'admin: member → owner' => [OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Owner, false],
    'admin: owner → member' => [OrganizationRole::Admin, OrganizationRole::Owner, OrganizationRole::Member, false],
    'member: member → admin' => [OrganizationRole::Member, OrganizationRole::Member, OrganizationRole::Admin, false],
]);

it('removes others only when the actor includes their role', function (OrganizationRole $actorRole, OrganizationRole $targetRole, bool $allowed) {
    actor($actorRole);
    $target = addMember($this->organization, $targetRole);

    $this->deleteJson("/api/members/{$target->id}")->assertStatus($allowed ? 204 : 403);

    expect($target->isMemberOf($this->organization))->toBe(! $allowed);
})->with([
    'owner removes owner' => [OrganizationRole::Owner, OrganizationRole::Owner, true],
    'owner removes admin' => [OrganizationRole::Owner, OrganizationRole::Admin, true],
    'admin removes admin' => [OrganizationRole::Admin, OrganizationRole::Admin, true],
    'admin removes member' => [OrganizationRole::Admin, OrganizationRole::Member, true],
    'admin removes owner' => [OrganizationRole::Admin, OrganizationRole::Owner, false],
    'member removes member' => [OrganizationRole::Member, OrganizationRole::Member, false],
]);

it('lets anyone leave', function (OrganizationRole $role) {
    $user = actor($role === OrganizationRole::Owner ? OrganizationRole::Admin : $role);

    $this->deleteJson("/api/members/{$user->id}")->assertNoContent();

    expect($user->isMemberOf($this->organization))->toBeFalse();
})->with([OrganizationRole::Admin, OrganizationRole::Member]);

it('tells the app what each member\'s row allows', function () {
    $admin = actor(OrganizationRole::Admin);
    $member = addMember($this->organization, OrganizationRole::Member);

    $rows = collect($this->getJson('/api/members')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->owner->id]['can'])->toBe(['update' => false, 'delete' => false])
        ->and($rows[$this->owner->id]['assignable_roles'])->toBe([])
        ->and($rows[$member->id]['can'])->toBe(['update' => true, 'delete' => true])
        ->and($rows[$member->id]['assignable_roles'])->toBe(['admin'])
        ->and($rows[$admin->id]['is_me'])->toBeTrue()
        ->and($rows[$admin->id]['can']['delete'])->toBeTrue(); // leaving
});

it('keeps at least one owner', function () {
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$this->owner->id}", ['role' => 'admin'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.role.0', 'An organization needs at least one owner. Make someone else an owner first.');
    $this->deleteJson("/api/members/{$this->owner->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['member']);

    expect($this->owner->isMemberOf($this->organization))->toBeTrue();
});

it('lets an owner step down once there is another owner', function () {
    $other = addMember($this->organization, OrganizationRole::Owner);
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$this->owner->id}", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');

    // Now the other owner is the last one.
    Sanctum::actingAs($other);
    $this->deleteJson("/api/members/{$other->id}")->assertUnprocessable();
});

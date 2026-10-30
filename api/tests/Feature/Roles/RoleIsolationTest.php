<?php

use App\Enums\DefaultRole;
use App\Models\Membership;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

// Org A cannot read, change or use org B's roles.

beforeEach(function () {
    Mail::fake();
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');
    $this->roleB = createRole($this->orgB, 'Secret B role');
    $this->memberA = addMember($this->orgA);
});

it('lists only the current organization\'s roles', function () {
    Sanctum::actingAs($this->ownerA);

    expect(array_column($this->getJson('/api/roles')->assertOk()->json('data'), 'name'))->toBe(['Owner', 'Admin', 'Member']);
});

it('gets 404 on another organization\'s role, which stays unchanged', function () {
    Sanctum::actingAs($this->ownerA);

    $this->patchJson("/api/roles/{$this->roleB->id}", ['name' => 'Hacked', 'permissions' => []])->assertNotFound();
    $this->deleteJson("/api/roles/{$this->roleB->id}")->assertNotFound();

    expect(Role::withoutGlobalScopes()->findOrFail($this->roleB->id)->name)->toBe('Secret B role');
});

it('can\'t assign or invite with another organization\'s role', function () {
    Sanctum::actingAs($this->ownerA);

    $this->patchJson("/api/members/{$this->memberA->id}", ['role_id' => $this->roleB->id])->assertJsonValidationErrors(['role_id']);
    $this->postJson('/api/invitations', ['email' => 'x@example.com', 'role_id' => $this->roleB->id])->assertJsonValidationErrors(['role_id']);

    expect($this->orgA->members()->findOrFail($this->memberA->id)->membership->role->name)->toBe('Member');
});

it('stays isolated for someone in both organizations who hasn\'t switched', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);
    Sanctum::actingAs($this->ownerA);

    expect(array_column($this->getJson('/api/roles')->json('data'), 'name'))->not->toContain('Secret B role');
    $this->patchJson("/api/roles/{$this->roleB->id}", ['name' => 'Hacked', 'permissions' => []])->assertNotFound();
    $this->patchJson("/api/members/{$this->memberA->id}", ['role_id' => $this->roleB->id])->assertJsonValidationErrors(['role_id']);
    // The role choices for members and invitations are A's only.
    $invitable = array_column($this->getJson('/api/invitations')->assertOk()->json('meta.invitable_roles'), 'name');
    $assignable = collect($this->getJson('/api/members')->assertOk()->json('data'))->flatMap(fn ($member) => array_column($member['assignable_roles'], 'name'));
    expect($invitable)->not->toContain('Secret B role')
        ->and($assignable->all())->not->toContain('Secret B role');
    // Each organization in the switcher shows the user's own role there.
    $roles = collect($this->getJson('/api/organizations')->json('data'))->pluck('role.name', 'name');
    expect($roles->all())->toBe(['Org A' => 'Owner', 'Org B' => 'Owner']);
});

it('creates roles in the current organization only', function () {
    Sanctum::actingAs($this->ownerA);

    $id = $this->postJson('/api/roles', ['name' => 'Where?', 'permissions' => [], 'organization_id' => $this->orgB->id])
        ->assertCreated()
        ->json('data.id');

    expect(Role::withoutGlobalScopes()->findOrFail($id)->organization_id)->toBe($this->orgA->id);
});

it('lets the same role name exist in two organizations', function () {
    Sanctum::actingAs($this->ownerA);

    $this->postJson('/api/roles', ['name' => 'Secret B role', 'permissions' => []])->assertCreated();
});

it('refuses, in the database, a membership pointing at another organization\'s role', function () {
    expect(fn () => DB::transaction(fn () => Membership::query()
        ->where('organization_id', $this->orgA->id)
        ->where('user_id', $this->memberA->id)
        ->update(['role_id' => $this->roleB->id])))->toThrow(QueryException::class);
});

it('refuses, in the database, an invitation pointing at another organization\'s role', function () {
    [$invitation] = createInvitation($this->orgA, 'ana@example.com');

    expect(fn () => DB::transaction(fn () => OrganizationInvitation::withoutGlobalScopes()
        ->whereKey($invitation->id)
        ->update(['role_id' => $this->roleB->id])))->toThrow(QueryException::class);
});

it('counts the role limit per organization', function () {
    foreach (range(1, Role::MAX_PER_ORGANIZATION) as $i) {
        createRole($this->orgB, "B role {$i}");
    }
    Sanctum::actingAs($this->ownerA);

    $this->postJson('/api/roles', ['name' => 'Still fine', 'permissions' => []])->assertCreated();
});

it('refuses, in the database, deleting a role members have', function () {
    $roleA = createRole($this->orgA, 'Used');
    addMember($this->orgA, $roleA);

    expect(fn () => DB::transaction(fn () => Role::withoutGlobalScopes()->whereKey($roleA->id)->delete()))->toThrow(QueryException::class);
});

<?php

use App\Actions\Roles\UpdateRole;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Mail\OrganizationInvitationMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner);
    Sanctum::actingAs($this->owner);
});

it('lists the roles, Owner first, with what they allow and how many have them', function () {
    addMember($this->organization, DefaultRole::Admin);
    addMember($this->organization);
    addMember($this->organization);

    $roles = $this->getJson('/api/roles')->assertOk()->json('data');

    expect(array_column($roles, 'name'))->toBe(['Owner', 'Admin', 'Member'])
        ->and(array_column($roles, 'members_count'))->toBe([1, 1, 2])
        ->and($roles[0]['is_owner'])->toBeTrue()
        ->and($roles[0]['permissions'])->toBe(array_map(fn (Permission $p) => $p->value, Permission::cases()))
        ->and($roles[0]['can'])->toBe(['update' => false, 'delete' => false])
        ->and($roles[2]['permissions'])->toBe(['tasks.create'])
        ->and($roles[2]['can'])->toBe(['update' => true, 'delete' => true]);
});

it('lists the permission catalogue with labels and groups', function () {
    $this->getJson('/api/permissions')
        ->assertOk()
        ->assertJsonCount(count(Permission::cases()), 'data')
        ->assertJsonPath('data.0', ['value' => 'tasks.create', 'label' => 'Create tasks, and edit or delete their own', 'group' => 'Tasks']);
});

it('creates a role, storing its permissions once and in catalogue order', function () {
    $this->postJson('/api/roles', ['name' => 'Viewer', 'permissions' => []])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Viewer')
        ->assertJsonPath('data.permissions', [])
        ->assertJsonPath('data.members_count', 0);

    $this->postJson('/api/roles', ['name' => 'Lead', 'permissions' => ['members.invite', 'tasks.manage']])
        ->assertCreated()
        ->assertJsonPath('data.permissions', ['tasks.manage', 'members.invite']);

    expect(Role::query()->where('name', 'Lead')->sole()->is_owner)->toBeFalse();
});

it('never creates a second owner role', function () {
    $this->postJson('/api/roles', ['name' => 'Boss', 'permissions' => [], 'is_owner' => true])->assertCreated();

    expect(Role::query()->where('is_owner', true)->count())->toBe(1);
});

it('renames a role and changes what it allows', function () {
    $member = $this->organization->role(DefaultRole::Member);

    $this->patchJson("/api/roles/{$member->id}", ['name' => 'Contributor', 'permissions' => ['tasks.create', 'members.invite']])
        ->assertOk()
        ->assertJsonPath('data.name', 'Contributor')
        ->assertJsonPath('data.permissions', ['tasks.create', 'members.invite']);

    // Keeping its own name (any case) is fine.
    $this->patchJson("/api/roles/{$member->id}", ['name' => 'CONTRIBUTOR', 'permissions' => []])->assertOk();
});

it('validates the role', function (array $body, string $field) {
    $this->postJson('/api/roles', $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'no name' => [['permissions' => []], 'name'],
    'blank name' => [['name' => '   ', 'permissions' => []], 'name'],
    'long name' => [['name' => str_repeat('a', 51), 'permissions' => []], 'name'],
    'taken name, other case' => [['name' => 'admin', 'permissions' => []], 'name'],
    'invisible character' => [['name' => "Admin\u{200B}", 'permissions' => []], 'name'],
    'control character' => [['name' => "Ad\tmin", 'permissions' => []], 'name'],
    'no permissions key' => [['name' => 'Viewer'], 'permissions'],
    'unknown permission' => [['name' => 'Viewer', 'permissions' => ['tasks.destroy']], 'permissions.0'],
    'repeated permission' => [['name' => 'Viewer', 'permissions' => ['tasks.create', 'tasks.create']], 'permissions.0'],
]);

it('refuses another role\'s name when renaming', function () {
    $member = $this->organization->role(DefaultRole::Member);

    $this->patchJson("/api/roles/{$member->id}", ['name' => 'Admin', 'permissions' => []])->assertJsonValidationErrors(['name']);
});

it('stops at the role limit', function () {
    foreach (range(1, Role::MAX_PER_ORGANIZATION - 3) as $i) {
        createRole($this->organization, "Role {$i}");
    }

    $this->postJson('/api/roles', ['name' => 'One more', 'permissions' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'An organization can have at most 50 roles.');
});

it('deletes a role nobody has', function () {
    $viewer = createRole($this->organization, 'Viewer');

    $this->deleteJson("/api/roles/{$viewer->id}")->assertNoContent();

    expect(Role::query()->find($viewer->id))->toBeNull();
});

it('refuses to delete a role members have, saying how many', function () {
    $viewer = createRole($this->organization, 'Viewer');
    addMember($this->organization, $viewer);
    addMember($this->organization, $viewer);

    $this->deleteJson("/api/roles/{$viewer->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.role.0', '2 members have this role. Give them another role first.');

    expect(Role::query()->find($viewer->id))->not->toBeNull();
});

it('makes pending invitations with a deleted role unusable', function () {
    Mail::fake();
    $viewer = createRole($this->organization, 'Viewer');
    [$invitation, $token] = createInvitation($this->organization, 'ana@example.com', $viewer);

    $this->deleteJson("/api/roles/{$viewer->id}")->assertNoContent();

    expect($invitation->fresh()->role_id)->toBeNull();
    // It still shows (with no role) and can be revoked, or replaced by a new invitation.
    $this->getJson('/api/invitations')->assertOk()->assertJsonPath('data.0.role', null)->assertJsonPath('data.0.can.delete', true);

    Sanctum::actingAs(User::factory()->create(['email' => 'ana@example.com']));
    $this->getJson("/api/join/{$token}")->assertNotFound();
    $this->postJson("/api/join/{$token}")->assertNotFound();

    Sanctum::actingAs($this->owner);
    $this->postJson('/api/invitations', ['email' => 'ana@example.com', 'role_id' => roleId(DefaultRole::Member)])->assertCreated();
});

it('assigns custom roles to members and invitations', function () {
    Mail::fake();
    $viewer = createRole($this->organization, 'Viewer');
    $ana = addMember($this->organization);

    $this->patchJson("/api/members/{$ana->id}", ['role_id' => $viewer->id])->assertOk()->assertJsonPath('data.role.name', 'Viewer');
    $this->postJson('/api/invitations', ['email' => 'new@example.com', 'role_id' => $viewer->id])
        ->assertCreated()
        ->assertJsonPath('data.role', ['id' => $viewer->id, 'name' => 'Viewer', 'is_owner' => false]);
});

it('shows a role name as text in the invitation email, never as a link', function () {
    $sneaky = createRole($this->organization, '[Claim](https://evil.example)');

    // Markdown::withSecuredEncoding() (AppServiceProvider) escapes what {{ }} prints.
    $html = (new OrganizationInvitationMail('Acme', 'Maya', $sneaky->name, 'https://app.example/join/x', 'soon'))->render();

    expect($html)->not->toContain('href="https://evil.example"')
        ->and($html)->toContain('[Claim](https://evil.example)');
});

it('answers 404 when saving a role deleted meanwhile', function () {
    $viewer = createRole($this->organization, 'Viewer');
    $stale = clone $viewer;
    $viewer->delete();

    expect(fn () => app(UpdateRole::class)->handle($stale, 'Viewer 2', []))
        ->toThrow(ModelNotFoundException::class);
});

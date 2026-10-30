<?php

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner, 'Acme');
});

it('lists the members by name with their role and when they joined', function () {
    $zed = addMember($this->organization, DefaultRole::Member, ['name' => 'Zed']);
    $ana = addMember($this->organization, DefaultRole::Admin, ['name' => 'Ana']);
    $this->owner->update(['name' => 'Maya']);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/members')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $ana->id)
        ->assertJsonPath('data.0.role.name', 'Admin')
        ->assertJsonPath('data.1.id', $this->owner->id)
        ->assertJsonPath('data.1.is_me', true)
        ->assertJsonPath('data.2.id', $zed->id)
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'joined_at', 'is_me', 'assignable_roles', 'can' => ['update', 'delete']]]]);
});

it('changes a role', function () {
    $ana = addMember($this->organization, DefaultRole::Member);
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$ana->id}", ['role_id' => roleId(DefaultRole::Admin)])
        ->assertOk()
        ->assertJsonPath('data.id', $ana->id)
        ->assertJsonPath('data.role.name', 'Admin');
});

it('validates the role', function (mixed $role) {
    $ana = addMember($this->organization);
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$ana->id}", ['role_id' => $role])->assertUnprocessable()->assertJsonValidationErrors(['role_id']);
})->with([null, 'admin', 999999]);

it('removes a member, who then can\'t act in the organization', function () {
    $ana = addMember($this->organization);
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/members/{$ana->id}")->assertNoContent();

    expect($ana->isMemberOf($this->organization))->toBeFalse()
        ->and($ana->fresh()->current_organization_id)->toBeNull();

    Sanctum::actingAs($ana->fresh());
    $this->getJson('/api/members')->assertForbidden();
});

it('moves someone who leaves their current organization to another of theirs', function () {
    $ana = addMember($this->organization);
    $zeta = Organization::factory()->withMember($ana)->create(['name' => 'Zeta']);
    $beta = Organization::factory()->withMember($ana)->create(['name' => 'Beta']);
    Sanctum::actingAs($ana);

    $this->deleteJson("/api/members/{$ana->id}")->assertNoContent();

    // Beta comes before Zeta by name.
    expect($ana->fresh()->current_organization_id)->toBe($beta->id)
        ->and($zeta->id)->not->toBe($beta->id);

    // A fresh model: actingAs keeps the in-memory user between requests.
    Sanctum::actingAs($ana->fresh());
    $this->getJson('/api/me')->assertJsonPath('data.current_organization.id', $beta->id);
});

it('leaves someone\'s current organization alone when they are removed from another', function () {
    $ana = addMember($this->organization);
    [, $other] = userInOrganization(name: 'Other');
    $other->members()->attach($ana, ['role_id' => $other->role(DefaultRole::Member)->id]);
    $ana->forceFill(['current_organization_id' => $other->id])->save();
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/members/{$ana->id}")->assertNoContent();

    expect($ana->fresh()->current_organization_id)->toBe($other->id);
});

it('answers 404 for someone who isn\'t a member', function () {
    [$stranger] = userInOrganization(name: 'Elsewhere');
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$stranger->id}", ['role_id' => roleId(DefaultRole::Admin)])->assertNotFound();
    $this->deleteJson("/api/members/{$stranger->id}")->assertNotFound();
    $this->deleteJson('/api/members/99999999999999999999')->assertNotFound();
});

it('needs a current organization and a login', function () {
    $this->getJson('/api/members')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/members')->assertForbidden();
});

it('unassigns the tasks of someone who leaves or is removed, in that organization only', function () {
    $member = addMember($this->organization);
    [, $other] = userInOrganization(DefaultRole::Owner, 'Other');
    $other->members()->attach($member, ['role_id' => $other->role(DefaultRole::Member)->id]);

    [$here, $elsewhere] = [
        inOrganization($this->organization, fn () => Task::factory()->assignedTo($member)->create()),
        inOrganization($other, fn () => Task::factory()->assignedTo($member)->create()),
    ];
    $unrelated = inOrganization($this->organization, fn () => Task::factory()->assignedTo($this->owner)->create());

    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    expect(Task::withoutGlobalScopes()->find($here->id)->assignee_id)->toBeNull()
        ->and(Task::withoutGlobalScopes()->find($elsewhere->id)->assignee_id)->toBe($member->id)
        ->and(Task::withoutGlobalScopes()->find($unrelated->id)->assignee_id)->toBe($this->owner->id);

    // Leaving does the same.
    Sanctum::actingAs($member);
    $member->forceFill(['current_organization_id' => $other->id])->save();
    $this->deleteJson("/api/members/{$member->id}")->assertNoContent();

    expect(Task::withoutGlobalScopes()->find($elsewhere->id)->assignee_id)->toBeNull();
});

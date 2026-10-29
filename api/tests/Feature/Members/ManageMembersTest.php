<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(OrganizationRole::Owner, 'Acme');
});

it('lists the members by name with their role and when they joined', function () {
    $zed = addMember($this->organization, OrganizationRole::Member, ['name' => 'Zed']);
    $ana = addMember($this->organization, OrganizationRole::Admin, ['name' => 'Ana']);
    $this->owner->update(['name' => 'Maya']);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/members')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $ana->id)
        ->assertJsonPath('data.0.role', 'admin')
        ->assertJsonPath('data.1.id', $this->owner->id)
        ->assertJsonPath('data.1.is_me', true)
        ->assertJsonPath('data.2.id', $zed->id)
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'joined_at', 'is_me', 'assignable_roles', 'can' => ['update', 'delete']]]]);
});

it('changes a role', function () {
    $ana = addMember($this->organization, OrganizationRole::Member);
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$ana->id}", ['role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.id', $ana->id)
        ->assertJsonPath('data.role', 'admin');
});

it('validates the role', function (mixed $role) {
    $ana = addMember($this->organization);
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$ana->id}", ['role' => $role])->assertUnprocessable()->assertJsonValidationErrors(['role']);
})->with([null, 'boss']);

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
    $other->members()->attach($ana, ['role' => OrganizationRole::Member]);
    $ana->forceFill(['current_organization_id' => $other->id])->save();
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/members/{$ana->id}")->assertNoContent();

    expect($ana->fresh()->current_organization_id)->toBe($other->id);
});

it('answers 404 for someone who isn\'t a member', function () {
    [$stranger] = userInOrganization(name: 'Elsewhere');
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/members/{$stranger->id}", ['role' => 'admin'])->assertNotFound();
    $this->deleteJson("/api/members/{$stranger->id}")->assertNotFound();
    $this->deleteJson('/api/members/99999999999999999999')->assertNotFound();
});

it('needs a current organization and a login', function () {
    $this->getJson('/api/members')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/members')->assertForbidden();
});

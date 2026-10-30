<?php

use App\Enums\DefaultRole;
use App\Models\Organization;
use Laravel\Sanctum\Sanctum;

it('switches to another organization the user belongs to', function () {
    [$user] = userInOrganization();
    $beta = Organization::factory()->withMember($user, DefaultRole::Admin)->create(['name' => 'Beta']);
    Sanctum::actingAs($user);

    $this->postJson("/api/organizations/{$beta->id}/switch")
        ->assertOk()
        ->assertJsonPath('data.id', $beta->id)
        ->assertJsonPath('data.role.name', 'Admin');

    expect($user->fresh()->current_organization_id)->toBe($beta->id);

    $this->getJson('/api/me')
        ->assertJsonPath('data.current_organization.id', $beta->id)
        ->assertJsonPath('data.current_organization.role.name', 'Admin');
});

it('refuses to switch to an organization the user does not belong to', function () {
    [$user, $acme] = userInOrganization();
    [, $otherOrganization] = userInOrganization(name: 'Other Corp');
    Sanctum::actingAs($user);

    $this->postJson("/api/organizations/{$otherOrganization->id}/switch")->assertNotFound();

    expect($user->fresh()->current_organization_id)->toBe($acme->id);
});

it('answers a missing organization the same way as a foreign one', function () {
    [$user, $acme] = userInOrganization();
    [, $foreign] = userInOrganization(name: 'Other Corp');
    Sanctum::actingAs($user);

    $missing = $this->postJson('/api/organizations/999999/switch')->assertNotFound();
    $notMine = $this->postJson("/api/organizations/{$foreign->id}/switch")->assertNotFound();

    // A plain "Not found." for both: nothing tells them apart, or names the model.
    expect($missing->json())->toBe(['message' => 'Not found.'])
        ->and($notMine->json())->toBe(['message' => 'Not found.'])
        ->and($user->fresh()->current_organization_id)->toBe($acme->id);
});

it('answers an id too long to be one with a 404, not an error', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/organizations/99999999999999999999999/switch')->assertNotFound();
});

it('refuses to switch back after the user was removed from the organization', function () {
    [$user, $acme] = userInOrganization();
    $beta = Organization::factory()->withMember($user)->create();
    $beta->members()->detach($user);
    Sanctum::actingAs($user);

    $this->postJson("/api/organizations/{$beta->id}/switch")->assertNotFound();

    expect($user->fresh()->current_organization_id)->toBe($acme->id);
});

it('rejects unauthenticated requests', function () {
    [, $acme] = userInOrganization();

    $this->postJson("/api/organizations/{$acme->id}/switch")->assertUnauthorized();
});

<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists only the organizations the user belongs to, by name, with their role', function () {
    [$user, $acme] = userInOrganization(OrganizationRole::Owner, 'Acme');
    $zeta = Organization::factory()->withMember($user, OrganizationRole::Member)->create(['name' => 'Zeta']);
    $beta = Organization::factory()->withMember($user, OrganizationRole::Admin)->create(['name' => 'Beta']);

    // Someone else's organization must not show up.
    [, $other] = userInOrganization(OrganizationRole::Owner, 'Other Corp');

    Sanctum::actingAs($user);

    $this->getJson('/api/organizations')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $acme->id)
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.1.id', $beta->id)
        ->assertJsonPath('data.1.role', 'admin')
        ->assertJsonPath('data.2.id', $zeta->id)
        ->assertJsonPath('data.2.role', 'member')
        ->assertJsonPath('meta.current_organization_id', $acme->id)
        ->assertJsonMissing(['id' => $other->id]);
});

it('reports no current organization when the user has none', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/organizations')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.current_organization_id', null);
});

it('rejects unauthenticated requests', function () {
    $this->getJson('/api/organizations')->assertUnauthorized();
});

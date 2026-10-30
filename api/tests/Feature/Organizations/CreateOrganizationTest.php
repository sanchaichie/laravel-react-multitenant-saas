<?php

use App\Enums\DefaultRole;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Models\Organization;
use Laravel\Sanctum\Sanctum;

it('creates an organization owned by the user and switches to it', function () {
    [$user] = userInOrganization();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/organizations', ['name' => '  New Co  '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Co')
        ->assertJsonPath('data.role.name', 'Owner');

    $organization = Organization::findOrFail($response->json('data.id'));

    expect($user->fresh()->current_organization_id)->toBe($organization->id)
        ->and($organization->members()->sole()->is($user))->toBeTrue()
        ->and($organization->members()->sole()->membership->role->name)->toBe(DefaultRole::Owner->label());

    $this->getJson('/api/me')->assertJsonPath('data.current_organization.id', $organization->id);
});

it('requires a name', function (mixed $name) {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/organizations', ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(Organization::count())->toBe(1);
})->with(['missing' => null, 'blank' => '   ']);

it('rejects a name that is too long', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    $this->postJson('/api/organizations', ['name' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('limits how many requests a user can make in a minute', function () {
    Sanctum::actingAs(userInOrganization()[0]);

    // Refused ones count too.
    foreach (range(1, 20) as $_) {
        $this->postJson('/api/organizations', ['name' => ''])->assertUnprocessable();
    }

    $this->postJson('/api/organizations', ['name' => 'One too many'])->assertTooManyRequests();
});

it('limits how many organizations a user can own', function () {
    [$user] = userInOrganization();
    foreach (range(2, StoreOrganizationRequest::MAX_OWNED) as $i) {
        Organization::factory()->withMember($user, DefaultRole::Owner)->create();
    }
    // Being a member (not the owner) elsewhere doesn't count.
    Organization::factory()->withMember($user, DefaultRole::Member)->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/organizations', ['name' => 'One too many'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'You can own at most 20 organizations.');

    expect(Organization::count())->toBe(StoreOrganizationRequest::MAX_OWNED + 1);
});

it('rejects unauthenticated requests', function () {
    $this->postJson('/api/organizations', ['name' => 'New Co'])->assertUnauthorized();

    expect(Organization::count())->toBe(0);
});

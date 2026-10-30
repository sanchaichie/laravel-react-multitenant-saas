<?php

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // A stand-in for the routes plans 06 and 07 put in the 'organization' group.
    Route::middleware(['api', 'auth:sanctum', 'organization'])->get('/api/test-current-organization', fn (CurrentOrganization $current) => [
        'id' => $current->id(),
        'role' => $current->role()?->name,
    ]);
});

it('lets a member act in their current organization', function () {
    [$user, $acme] = userInOrganization(DefaultRole::Admin);
    Sanctum::actingAs($user);

    $this->getJson('/api/test-current-organization')
        ->assertOk()
        ->assertExactJson(['id' => $acme->id, 'role' => 'Admin']);
});

it('refuses with 403 when the user has no current organization', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/test-current-organization')
        ->assertForbidden()
        ->assertJsonPath('message', 'No active organization.');
});

it('re-checks membership on every request', function () {
    [$user, $acme] = userInOrganization();
    Sanctum::actingAs($user);
    $this->getJson('/api/test-current-organization')->assertOk();

    // Removed while their current_organization_id still points there.
    $acme->members()->detach($user);

    $this->getJson('/api/test-current-organization')->assertForbidden();
    $this->getJson('/api/me')->assertJsonPath('data.current_organization', null);
});

it('accepts X-Organization-Id when it names the current organization', function () {
    [$user, $acme] = userInOrganization();
    Sanctum::actingAs($user);

    $this->withHeader('X-Organization-Id', (string) $acme->id)
        ->getJson('/api/test-current-organization')
        ->assertOk()
        ->assertJsonPath('id', $acme->id);
});

it('refuses with 409 when another tab switched (X-Organization-Id is a precondition, never a choice)', function () {
    [$user, $acme] = userInOrganization();
    $beta = Organization::factory()->withMember($user)->create();
    Sanctum::actingAs($user);

    // This tab still thinks Acme is current; another tab switched to Beta.
    $this->postJson("/api/organizations/{$beta->id}/switch")->assertOk();

    $this->withHeader('X-Organization-Id', (string) $acme->id)
        ->getJson('/api/test-current-organization')
        ->assertStatus(409)
        ->assertJsonPath('code', 'organization_changed');

    // Naming an organization the user isn't in doesn't act in it either.
    [, $foreign] = userInOrganization(name: 'Other Corp');
    $this->withHeader('X-Organization-Id', (string) $foreign->id)
        ->getJson('/api/test-current-organization')
        ->assertStatus(409);
});

it('runs code in another organization and then restores the request\'s own', function () {
    [$user, $acme] = userInOrganization();
    $other = Organization::factory()->create();
    Sanctum::actingAs($user);
    $current = app(CurrentOrganization::class);

    expect($current->id())->toBe($acme->id);

    $seen = $current->run($other, fn () => $current->id());
    expect($seen)->toBe($other->id)
        ->and($current->id())->toBe($acme->id);

    // Restored even when the code throws.
    expect(fn () => $current->run($other, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect($current->id())->toBe($acme->id);
});

it('keeps each organization\'s roles apart in run(), and refuses another organization\'s role', function () {
    [$user, $acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    [, $other] = userInOrganization(DefaultRole::Owner, 'Other');
    Sanctum::actingAs($user);
    $current = app(CurrentOrganization::class);

    expect($current->roles()->pluck('organization_id')->unique()->all())->toBe([$acme->id])
        ->and($current->run($other, fn () => $current->roles()->pluck('organization_id')->unique()->all()))->toBe([$other->id])
        ->and($current->roles()->pluck('organization_id')->unique()->all())->toBe([$acme->id])
        ->and($current->role()->organization_id)->toBe($acme->id)
        ->and(fn () => $current->set($acme, $other->role(DefaultRole::Owner)))->toThrow(LogicException::class);
});

<?php

use App\Enums\DefaultRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner, 'Acme');
    $this->owner->update(['name' => 'Maya']);
    [$this->invitation, $this->token] = createInvitation($this->organization, 'ana@example.com', DefaultRole::Admin, ['invited_by' => $this->owner->id]);
    // Ana registered from the link: her own organization, email not verified yet.
    [$this->ana] = userInOrganization(name: 'Ana\'s organization');
    $this->ana->forceFill(['email' => 'ana@example.com', 'email_verified_at' => null])->save();
});

it('shows who invited you to what', function () {
    Sanctum::actingAs($this->ana);

    $this->getJson("/api/join/{$this->token}")
        ->assertOk()
        ->assertExactJson(['data' => [
            'organization' => ['name' => 'Acme'],
            'invited_by' => 'Maya',
            'role' => ['id' => roleId(DefaultRole::Admin, $this->organization), 'name' => 'Admin', 'is_owner' => false],
            'email' => 'ana@example.com',
            'expires_at' => $this->invitation->expires_at->toJSON(),
            'already_member' => false,
        ]]);
});

it('joins with the invited role, switches to the organization and verifies the email', function () {
    Sanctum::actingAs($this->ana);

    $this->postJson("/api/join/{$this->token}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->organization->id)
        ->assertJsonPath('data.role.name', 'Admin');

    $ana = $this->ana->fresh();
    expect($ana->isMemberOf($this->organization))->toBeTrue()
        ->and($ana->current_organization_id)->toBe($this->organization->id)
        ->and($ana->hasVerifiedEmail())->toBeTrue()
        ->and($this->invitation->fresh()->accepted_at)->not->toBeNull();
});

it('refuses another account, saying why', function () {
    Sanctum::actingAs(User::factory()->create(['email' => 'ben@example.com']));

    $this->getJson("/api/join/{$this->token}")
        ->assertForbidden()
        ->assertJsonPath('message', 'This invitation was sent to a different email address.');
    $this->postJson("/api/join/{$this->token}")->assertForbidden();

    expect($this->organization->members()->count())->toBe(1);
});

it('answers every unusable link with the same 404', function (Closure $makeUnusable) {
    $token = $makeUnusable($this);
    Sanctum::actingAs($this->ana);

    $this->getJson("/api/join/{$token}")->assertNotFound()->assertJsonPath('message', 'This invitation is no longer valid.');
    $this->postJson("/api/join/{$token}")->assertNotFound()->assertJsonPath('message', 'This invitation is no longer valid.');

    expect($this->ana->isMemberOf($this->organization))->toBeFalse();
})->with([
    'unknown' => [fn () => str_repeat('a', 48)],
    'revoked' => [function ($test) {
        $test->invitation->delete();

        return $test->token;
    }],
    'expired' => [function ($test) {
        $test->invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        return $test->token;
    }],
    'used' => [function ($test) {
        $test->invitation->forceFill(['accepted_at' => now()])->save();

        return $test->token;
    }],
]);

it('can be used only once', function () {
    Sanctum::actingAs($this->ana);

    $this->postJson("/api/join/{$this->token}")->assertOk();
    $this->postJson("/api/join/{$this->token}")->assertNotFound();
});

it('keeps the role of someone who is already a member', function () {
    $this->organization->members()->attach($this->ana, ['role_id' => $this->organization->role(DefaultRole::Member)->id]);
    Sanctum::actingAs($this->ana);

    $this->getJson("/api/join/{$this->token}")->assertJsonPath('data.already_member', true);
    $this->postJson("/api/join/{$this->token}")->assertOk()->assertJsonPath('data.role.name', 'Member');
});

it('needs a login, and a token of the right shape', function () {
    $this->getJson("/api/join/{$this->token}")->assertUnauthorized();

    Sanctum::actingAs($this->ana);
    $this->getJson('/api/join/short')->assertNotFound();
});

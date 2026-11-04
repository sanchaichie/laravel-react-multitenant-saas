<?php

use App\Enums\DefaultRole;
use App\Models\User;

it('returns the logged-in user with their current organization and role', function () {
    [$user, $acme] = userInOrganization(DefaultRole::Admin, 'Acme');
    $user->update(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $this->withToken($user->createToken('web')->plainTextToken)
        ->getJson('/api/me')
        ->assertOk()
        ->assertExactJsonStructure(['data' => [
            'id', 'name', 'email', 'email_verified', 'pending_email', 'created_at',
            'current_organization' => ['id', 'name', 'role', 'permissions', 'created_at'],
        ]])
        ->assertJsonPath('data.name', 'Ada Lovelace')
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.current_organization.id', $acme->id)
        ->assertJsonPath('data.current_organization.name', 'Acme')
        ->assertJsonPath('data.current_organization.role.name', 'Admin')
        ->assertJsonPath('data.current_organization.permissions', ['tasks.create', 'tasks.manage', 'members.invite', 'members.manage', 'audit_log.view', 'ai.use', 'ai_usage.view'])
        ->assertJsonPath('data.email_verified', true);
});

it('has no current organization without one', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('web')->plainTextToken)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.current_organization', null);
});

it('rejects a request without a token', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

it('rejects an unknown token', function () {
    $this->withToken('1|not-a-real-token')->getJson('/api/me')->assertUnauthorized();
});

it('answers JSON 401 even without an Accept header', function () {
    // No login page to redirect to: this used to be a 500 in saas-starter.
    $response = $this->get('/api/me');

    $response->assertUnauthorized();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

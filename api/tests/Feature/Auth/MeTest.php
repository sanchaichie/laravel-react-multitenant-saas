<?php

use App\Models\User;

it('returns the logged-in user', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $this->withToken($user->createToken('web')->plainTextToken)
        ->getJson('/api/me')
        ->assertOk()
        ->assertExactJsonStructure(['data' => ['id', 'name', 'email', 'created_at']])
        ->assertJsonPath('data.name', 'Ada Lovelace')
        ->assertJsonPath('data.email', 'ada@example.com');
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

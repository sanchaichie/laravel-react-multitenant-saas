<?php

use App\Models\User;

it('revokes only the current token', function () {
    $user = User::factory()->create();
    $laptop = $user->createToken('laptop')->plainTextToken;
    $phone = $user->createToken('phone')->plainTextToken;

    $this->withToken($laptop)->postJson('/api/logout')->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['phone']);

    // A real request after logout: forget the user the guard resolved for the previous one.
    $this->app['auth']->forgetGuards();
    $this->withToken($laptop)->getJson('/api/me')->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withToken($phone)->getJson('/api/me')->assertOk();
});

it('requires a token', function () {
    $this->postJson('/api/logout')->assertUnauthorized();
});

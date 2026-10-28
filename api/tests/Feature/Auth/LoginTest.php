<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'secret-password',
    ]);
});

it('logs in with valid credentials and returns a working token', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'created_at'], 'token'])
        ->assertJsonPath('user.id', $this->user->id)
        ->assertJsonMissingPath('user.password');

    expect($this->user->tokens()->count())->toBe(1);

    $this->withToken($response->json('token'))
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com');
});

it('accepts the email in any case', function () {
    $this->postJson('/api/login', [
        'email' => 'Ada@Example.com',
        'password' => 'secret-password',
    ])->assertOk()->assertJsonPath('user.id', $this->user->id);
});

it('rejects a wrong password', function () {
    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonMissingPath('token');

    expect($this->user->tokens()->count())->toBe(0);
});

it('rejects an unknown email with the same error as a wrong password', function () {
    $wrongPassword = $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])->json('errors.email');

    $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'secret-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email', $wrongPassword);
});

it('requires email and password', function () {
    $this->postJson('/api/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects an invalid email format', function () {
    $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'secret-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
    }

    $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('limits guesses at one account across IPs', function () {
    foreach (range(1, 30) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.intdiv($i, 6)])
            ->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->postJson('/api/login', ['email' => 'Ada@example.com', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
});

it('keeps another account usable from the same IP', function () {
    User::factory()->create(['email' => 'grace@example.com', 'password' => 'secret-password']);

    foreach (range(1, 6) as $_) {
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
    }

    $this->postJson('/api/login', ['email' => 'grace@example.com', 'password' => 'secret-password'])->assertOk();
});

it('names the token after the device, or "web"', function (?string $device, string $name) {
    $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'device_name' => $device,
    ])->assertOk();

    expect($this->user->tokens()->sole()->name)->toBe($name);
})->with([
    'given' => ['Ada\'s phone', 'Ada\'s phone'],
    'omitted' => [null, 'web'],
]);

it('counts login attempts separately from registrations', function () {
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password']);
    }

    $this->postJson('/api/register', [
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated();
});

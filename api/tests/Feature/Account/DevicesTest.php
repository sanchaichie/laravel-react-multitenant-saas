<?php

use App\Models\User;
use App\Support\DeviceName;

const CHROME_ON_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

it('stores where a token was issued on login', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->withHeader('User-Agent', CHROME_ON_MAC)
        ->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk();

    $token = $user->tokens()->sole();
    expect($token->name)->toBe('Chrome on macOS')
        ->and($token->ip_address)->toBe('127.0.0.1')
        ->and($token->user_agent)->toBe(CHROME_ON_MAC);
});

it('keeps a device name the client sends', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'password', 'device_name' => 'CLI'])->assertOk();

    expect($user->tokens()->sole()->name)->toBe('CLI');
});

it('lists only your own devices and marks this one, without the token', function () {
    $user = User::factory()->create();
    $here = $user->createToken('Here')->plainTextToken;
    $user->createToken('Elsewhere');
    User::factory()->create()->createToken('Not mine');

    $response = $this->withToken($here)->getJson('/api/account/devices')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->sort()->values()->all())->toBe(['Elsewhere', 'Here'])
        ->and(collect($response->json('data'))->firstWhere('name', 'Here')['is_current'])->toBeTrue()
        ->and(collect($response->json('data'))->firstWhere('name', 'Elsewhere')['is_current'])->toBeFalse()
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'ip_address', 'last_used_at', 'created_at'])
        ->and($response->json('data.0'))->not->toHaveKey('token');
});

it('revokes another of your devices', function () {
    $user = User::factory()->create();
    $here = $user->createToken('Here')->plainTextToken;
    $elsewhere = $user->createToken('Elsewhere');

    $this->withToken($here)->deleteJson("/api/account/devices/{$elsewhere->accessToken->id}")->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($elsewhere->plainTextToken)->getJson('/api/me')->assertUnauthorized();
});

it('revokes this device, which logs it out', function () {
    $user = User::factory()->create();
    $here = $user->createToken('Here');

    $this->withToken($here->plainTextToken)->deleteJson("/api/account/devices/{$here->accessToken->id}")->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($here->plainTextToken)->getJson('/api/me')->assertUnauthorized();
});

it("cannot revoke someone else's token", function () {
    $user = User::factory()->create();
    $other = User::factory()->create()->createToken('Theirs');

    $this->actingAs($user, 'sanctum')->deleteJson("/api/account/devices/{$other->accessToken->id}")->assertNotFound();

    expect($other->accessToken->fresh())->not->toBeNull();
});

it('names devices from their User-Agent', function (?string $userAgent, string $name) {
    expect(DeviceName::fromUserAgent($userAgent))->toBe($name);
})->with([
    [CHROME_ON_MAC, 'Chrome on macOS'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge on Windows'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox on Linux'],
    ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Chrome on Android'],
    ['curl/8.7.1', 'Unknown device'],
    [null, 'Unknown device'],
]);

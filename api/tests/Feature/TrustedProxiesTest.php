<?php

use App\Models\User;
use App\Providers\AppServiceProvider;

// The Next.js app calls the API from its server and passes the browser's address
// in X-Forwarded-For. Only addresses in TRUSTED_PROXIES may do that.

function loginFrom(string $remoteAddress, ?string $forwardedFor = null): string
{
    $user = User::factory()->create(['email' => 'ada@example.com']);

    test()->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders($forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor])
        ->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk();

    return $user->tokens()->sole()->ip_address;
}

it('takes the client IP from a trusted proxy', function () {
    AppServiceProvider::trustProxies(['10.0.0.5']);

    expect(loginFrom('10.0.0.5', '203.0.113.9'))->toBe('203.0.113.9');
});

it('accepts a CIDR range', function () {
    AppServiceProvider::trustProxies(['10.0.0.0/24']);

    expect(loginFrom('10.0.0.77', '203.0.113.9'))->toBe('203.0.113.9');
});

it('ignores X-Forwarded-For from anyone else', function () {
    AppServiceProvider::trustProxies(['10.0.0.5']);

    // A client can't choose its own IP (and so its rate-limit bucket).
    expect(loginFrom('198.51.100.7', '203.0.113.9'))->toBe('198.51.100.7');
});

it('trusts nobody by default', function () {
    expect(config('app.trusted_proxies'))->toBe([])
        ->and(loginFrom('198.51.100.7', '203.0.113.9'))->toBe('198.51.100.7');
});

it('refuses to trust everyone', function () {
    AppServiceProvider::trustProxies(['*']);
})->throws(LogicException::class, 'TRUSTED_PROXIES must list addresses, never "*".');

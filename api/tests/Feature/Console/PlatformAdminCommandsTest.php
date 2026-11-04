<?php

use App\Enums\PlatformAuditAction;
use App\Models\User;

it('grants and revokes platform admin, and logs both with no actor', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);

    $this->artisan('admin:grant', ['email' => 'ADA@example.com'])
        ->expectsOutputToContain('ada@example.com is now a platform admin.')
        ->assertSuccessful();
    expect($user->fresh()->is_platform_admin)->toBeTrue();

    $this->artisan('admin:revoke', ['email' => 'ada@example.com'])
        ->expectsOutputToContain('no longer a platform admin')
        ->assertSuccessful();
    expect($user->fresh()->is_platform_admin)->toBeFalse();

    $log = platformAuditLog();
    expect($log->pluck('action')->all())->toBe([PlatformAuditAction::AdminGranted, PlatformAuditAction::AdminRevoked])
        ->and($log->pluck('actor_id')->filter()->all())->toBe([])
        ->and($log->pluck('ip_address')->filter()->all())->toBe([])
        ->and($log->pluck('subject_id')->unique()->all())->toBe([$user->id]);
});

it('refuses to grant an account without two-factor', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('admin:grant', ['email' => 'ada@example.com'])
        ->expectsOutputToContain('two-factor authentication turned on first')
        ->assertFailed();

    expect($user->fresh()->is_platform_admin)->toBeFalse()
        ->and(platformAuditLog())->toBeEmpty();
});

it('fails for an unknown email', function (string $command) {
    $this->artisan($command, ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No user with that email address.')
        ->assertFailed();
})->with(['admin:grant', 'admin:revoke']);

it('changes nothing when already in that state', function () {
    User::factory()->withTwoFactor()->platformAdmin()->create(['email' => 'ada@example.com']);
    User::factory()->create(['email' => 'bob@example.com']);

    $this->artisan('admin:grant', ['email' => 'ada@example.com'])->assertSuccessful();
    $this->artisan('admin:revoke', ['email' => 'bob@example.com'])->assertSuccessful();

    expect(platformAuditLog())->toBeEmpty();
});

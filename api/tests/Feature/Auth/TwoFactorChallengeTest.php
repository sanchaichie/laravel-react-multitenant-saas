<?php

use App\Actions\Auth\CheckTwoFactorCode;
use App\Models\User;
use App\Notifications\RecoveryCodeUsed;
use App\Notifications\TwoFactorLockedOut;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

const TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

beforeEach(function () {
    Notification::fake();
    $this->freezeTime();
    $this->user = User::factory()->withTwoFactor(TWO_FACTOR_SECRET)->create(['email' => 'ada@example.com', 'password' => 'secret-password']);
});

function codeNow(int $offset = 0): string
{
    return Totp::codeAt(TWO_FACTOR_SECRET, Totp::step(now()->getTimestamp()) + $offset);
}

function wrongCode(): string
{
    return codeNow() === '000000' ? '111111' : '000000';
}

function loginChallenge(): string
{
    return test()->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])
        ->assertOk()
        ->json('challenge');
}

it('asks for a code instead of issuing a token when two-factor is on', function () {
    $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])
        ->assertOk()
        ->assertJsonPath('two_factor', true)
        ->assertJsonStructure(['challenge'])
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('user');

    expect($this->user->tokens()->count())->toBe(0);
});

it('still refuses a wrong password without a challenge', function () {
    $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonMissingPath('challenge');
});

it('logs in with a right code and a working token', function () {
    $response = $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow()])
        ->assertOk()
        ->assertJsonPath('user.id', $this->user->id)
        ->assertJsonPath('user.two_factor_enabled', true);

    $this->withToken($response->json('token'))->getJson('/api/me')->assertOk();
});

it('accepts a code from the step before, for slow clocks', function () {
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow(-1)])->assertOk();
});

it('refuses a code that was already used, even with a new challenge', function () {
    $code = codeNow();
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => $code])->assertOk();

    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('can use a challenge only once', function () {
    $challenge = loginChallenge();
    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => codeNow()])->assertOk();

    $this->travel(31)->seconds();
    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);
});

it('refuses a wrong code and drops the challenge after 5 tries', function () {
    $challenge = loginChallenge();

    foreach (range(1, 4) as $try) {
        $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => wrongCode()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    // The 5th wrong code says so at once, instead of on the next try.
    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => wrongCode()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);

    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);

    expect($this->user->tokens()->count())->toBe(0);
});

it('locks the account after 10 wrong codes across challenges, even for a right code, and emails it', function () {
    // Per account, not per IP: the route throttle is off to stand in for many IPs.
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, 10) as $try) {
        $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => wrongCode()])
            ->assertUnprocessable();
    }
    Notification::assertSentToTimes($this->user, TwoFactorLockedOut::class, 1);

    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge'])
        ->assertJsonPath('errors.challenge.0', 'Too many wrong codes for this account. Try again in 15 minutes.');
    expect($this->user->tokens()->count())->toBe(0);

    // Other accounts aren't affected, and it lifts after 15 minutes.
    $this->travel(15)->minutes();
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow()])->assertOk();
});

it('keeps counting wrong codes after a right one: the owner logging in never resets them', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, 9) as $try) {
        $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => wrongCode()])->assertUnprocessable();
    }
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow()])->assertOk();

    $this->travel(31)->seconds();
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => wrongCode()])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);
});

it('never counts right codes toward the limit', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, 12) as $login) {
        $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow()])->assertOk();
        $this->travel(30)->seconds();
    }
});

it('refuses tries over the limit before checking their code, so racing requests cannot get past it', function () {
    RateLimiter::increment(CheckTwoFactorCode::limiterKey($this->user->id), CheckTwoFactorCode::LOCKOUT_SECONDS, CheckTwoFactorCode::MAX_FAILURES);

    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);
    expect($this->user->fresh()->two_factor_last_used_step)->toBeNull();
});

it('refuses a challenge made before the password changed', function () {
    $challenge = loginChallenge();
    $this->user->forceFill(['password' => 'another-password'])->save();

    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);
});

it('expires a challenge after 5 minutes', function () {
    $challenge = loginChallenge();
    $this->travel(301)->seconds();

    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);
});

it('refuses an unknown challenge', function () {
    $this->postJson('/api/two-factor-challenge', ['challenge' => str_repeat('x', 40), 'code' => codeNow()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge']);
});

it('validates the code and needs one of the two', function () {
    $this->postJson('/api/two-factor-challenge', ['challenge' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'recovery_code']);

    $this->postJson('/api/two-factor-challenge', ['challenge' => 'x', 'code' => '12ab56'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('logs in with a recovery code once, removes it and emails the account', function () {
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'recovery_code' => 'AAAAA-bbbbb '])
        ->assertOk()
        ->assertJsonStructure(['token']);

    expect($this->user->fresh()->two_factor_recovery_codes)->toBe([RecoveryCodes::hash('ccccc-ddddd')]);
    Notification::assertSentTo($this->user, RecoveryCodeUsed::class, fn (RecoveryCodeUsed $n) => $n->remaining === 1);

    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'recovery_code' => 'aaaaa-bbbbb'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recovery_code']);
});

it("cannot log in as another user with someone else's code", function () {
    $otherSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $other = User::factory()->withTwoFactor($otherSecret)->create();
    $other->createToken('phone');
    // A moment where the two secrets give different codes.
    $this->travelTo(CarbonImmutable::createFromTimestamp(1_700_000_000));
    $otherCode = Totp::codeAt($otherSecret, Totp::step(now()->getTimestamp()));
    expect($otherCode)->not->toBe(codeNow());

    // A challenge belongs to the user whose password made it.
    $this->postJson('/api/two-factor-challenge', ['challenge' => loginChallenge(), 'code' => $otherCode])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($other->tokens()->count())->toBe(1)
        ->and($this->user->tokens()->count())->toBe(0);
});

it('takes the user only from the challenge, never from the request', function () {
    $other = User::factory()->withTwoFactor()->create();

    $this->postJson('/api/two-factor-challenge', [
        'challenge' => loginChallenge(),
        'code' => codeNow(),
        'user_id' => $other->id,
        'email' => $other->email,
    ])->assertOk()->assertJsonPath('user.id', $this->user->id);

    expect($other->tokens()->count())->toBe(0);
});

it('gives the token the device name sent with the password', function () {
    $challenge = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password', 'device_name' => 'Work laptop'])
        ->json('challenge');

    $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => codeNow()])->assertOk();

    expect($this->user->tokens()->sole()->name)->toBe('Work laptop');
});

it('throttles the challenge endpoint', function () {
    foreach (range(1, 6) as $try) {
        $this->postJson('/api/two-factor-challenge', ['challenge' => 'x', 'code' => '123456'])->assertUnprocessable();
    }

    $this->postJson('/api/two-factor-challenge', ['challenge' => 'x', 'code' => '123456'])->assertTooManyRequests();
});

it('asks for a code after a password reset instead of logging in', function () {
    $this->user->createToken('laptop');

    $response = $this->postJson('/api/reset-password', [
        'token' => Password::broker()->createToken($this->user),
        'email' => 'ada@example.com',
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ])->assertOk()
        ->assertJsonPath('two_factor', true)
        ->assertJsonMissingPath('token');

    expect($this->user->tokens()->count())->toBe(0);

    $this->postJson('/api/two-factor-challenge', ['challenge' => $response->json('challenge'), 'code' => codeNow()])
        ->assertOk()
        ->assertJsonStructure(['token']);
});

it('ignores a setup that was started but not confirmed', function () {
    $user = User::factory()->create(['email' => 'bob@example.com', 'password' => 'secret-password', 'two_factor_secret' => TWO_FACTOR_SECRET]);

    $this->postJson('/api/login', ['email' => 'bob@example.com', 'password' => 'secret-password'])
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);
});

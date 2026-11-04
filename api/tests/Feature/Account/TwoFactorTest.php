<?php

use App\Actions\Account\ConfirmTwoFactor;
use App\Actions\Auth\CheckTwoFactorCode;
use App\Models\User;
use App\Notifications\RecoveryCodesRegenerated;
use App\Notifications\ResetPassword;
use App\Notifications\TwoFactorDisabled;
use App\Notifications\TwoFactorEnabled;
use App\Notifications\TwoFactorLockedOut;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

const ACCOUNT_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

beforeEach(function () {
    Notification::fake();
    $this->freezeTime();
});

function currentCode(string $secret = ACCOUNT_SECRET, int $offset = 0): string
{
    return Totp::codeAt($secret, Totp::step(now()->getTimestamp()) + $offset);
}

function notCurrentCode(string $secret = ACCOUNT_SECRET): string
{
    foreach (['000000', '111111', '222222'] as $code) {
        if (Totp::verify($secret, $code) === null) {
            return $code;
        }
    }

    throw new RuntimeException('No wrong code found.');
}

it('needs a logged-in user for every two-factor endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['post', '/api/account/two-factor'],
    ['post', '/api/account/two-factor/confirm'],
    ['post', '/api/account/two-factor/recovery-codes'],
    ['delete', '/api/account/two-factor'],
]);

it('needs the current password to start the setup', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/account/two-factor', ['current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('starts the setup with a secret and an otpauth link, still off', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/account/two-factor', ['current_password' => 'password'])->assertOk();

    $secret = $response->json('data.secret');
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($response->json('data.otpauth_uri'))->toStartWith('otpauth://totp/')->toContain('secret='.$secret)->toContain('ada%40example.com')
        ->and($user->fresh()->two_factor_secret)->toBe($secret)
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    // Encrypted at rest.
    expect(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->not->toContain($secret);

    $this->getJson('/api/me')->assertJsonPath('data.two_factor_enabled', false);
});

it('turns two-factor on with a right code, returns recovery codes and signs out other devices', function () {
    $user = User::factory()->create();
    $user->createToken('phone');
    $current = $user->createToken('laptop');

    $secret = $this->withToken($current->plainTextToken)
        ->postJson('/api/account/two-factor', ['current_password' => 'password'])
        ->json('data.secret');

    $response = $this->withToken($current->plainTextToken)
        ->postJson('/api/account/two-factor/confirm', ['code' => currentCode($secret)])
        ->assertOk()
        ->assertJsonPath('data.user.two_factor_enabled', true);
    $codes = $response->json('data.recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($codes[0])->toMatch('/^[a-z0-9]{5}-[a-z0-9]{5}$/')
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toBe(RecoveryCodes::hashAll($codes))
        ->and($user->tokens()->pluck('id')->all())->toBe([$current->accessToken->id]);

    Notification::assertSentTo($user, TwoFactorEnabled::class, fn (TwoFactorEnabled $n) => $n->confirmedAt === $user->fresh()->two_factor_confirmed_at->getTimestamp());
});

it('leaves two-factor off after a wrong code', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $secret = $this->postJson('/api/account/two-factor', ['current_password' => 'password'])->json('data.secret');

    $this->postJson('/api/account/two-factor/confirm', ['code' => notCurrentCode($secret)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    Notification::assertNothingSent();
});

it('refuses to confirm without a started setup', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/account/two-factor/confirm', ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('refuses to start again while two-factor is on, keeping the secret', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/account/two-factor', ['current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['two_factor']);

    expect($user->fresh()->two_factor_secret)->toBe(ACCOUNT_SECRET);
});

it('needs the password and a current code to turn two-factor off', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);

    $this->deleteJson('/api/account/two-factor', ['current_password' => 'wrong', 'code' => currentCode()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'recovery_code']);
    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'code' => notCurrentCode()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    Notification::assertNothingSent();
});

it('turns two-factor off with the password and a code, and emails the account', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);

    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'code' => currentCode()])
        ->assertOk()
        ->assertJsonPath('data.two_factor_enabled', false);

    expect($user->fresh())
        ->two_factor_secret->toBeNull()
        ->two_factor_recovery_codes->toBeNull()
        ->two_factor_confirmed_at->toBeNull()
        ->two_factor_last_used_step->toBeNull();
    Notification::assertSentTo($user, TwoFactorDisabled::class);
});

it('turns two-factor off with a recovery code when the phone is gone', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);

    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'recovery_code' => 'zzzzz-zzzzz'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recovery_code']);

    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'recovery_code' => 'ccccc-ddddd'])->assertOk();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses a code that was already used to log in', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_last_used_step' => Totp::step(now()->getTimestamp())]);
    Sanctum::actingAs($user);

    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'code' => currentCode()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->travel(30)->seconds();
    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'code' => currentCode()])->assertOk();
});

it('makes new recovery codes with the password and a code, and the old ones stop working', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'wrong', 'code' => currentCode()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
    $this->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password', 'code' => notCurrentCode()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
    // A recovery code isn't enough for a new set.
    $this->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password', 'recovery_code' => 'aaaaa-bbbbb'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $codes = $this->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password', 'code' => currentCode()])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and($user->fresh()->two_factor_recovery_codes)->toBe(RecoveryCodes::hashAll($codes))
        ->and($user->fresh()->two_factor_recovery_codes)->not->toContain(RecoveryCodes::hash('aaaaa-bbbbb'));
    Notification::assertSentTo($user, RecoveryCodesRegenerated::class);
});

it('refuses new recovery codes while two-factor is off', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password', 'code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['two_factor']);
});

it('counts wrong codes here toward the same per-account limit as logging in', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);
    RateLimiter::increment(CheckTwoFactorCode::limiterKey($user->id), CheckTwoFactorCode::LOCKOUT_SECONDS, CheckTwoFactorCode::MAX_FAILURES - 1);

    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'code' => notCurrentCode()])
        ->assertUnprocessable();
    Notification::assertSentToTimes($user, TwoFactorLockedOut::class, 1);

    // Paused, even with the right code.
    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'code' => currentCode()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'Too many wrong codes for this account. Try again in 15 minutes.');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('never shows the secret or recovery codes in the user', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);

    $json = $this->getJson('/api/me')->assertOk()->assertJsonPath('data.two_factor_enabled', true)->getContent();

    expect($json)->not->toContain('two_factor_secret')
        ->not->toContain('JBSWY3DPEHPK3PXP')
        ->not->toContain('recovery')
        ->and($user->fresh()->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_used_step']);
});

it('only changes the logged-in user', function () {
    $user = User::factory()->create();
    $other = User::factory()->withTwoFactor()->create();
    $other->createToken('phone');
    $otherCodes = $other->two_factor_recovery_codes;
    Sanctum::actingAs($user);

    $secret = $this->postJson('/api/account/two-factor', ['current_password' => 'password'])->assertOk()->json('data.secret');
    $this->postJson('/api/account/two-factor/confirm', ['code' => currentCode($secret)])->assertOk();
    $this->travel(30)->seconds();
    $this->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password', 'code' => currentCode($secret)])->assertOk();
    $this->travel(30)->seconds();
    $this->deleteJson('/api/account/two-factor', ['current_password' => 'password', 'code' => currentCode($secret)])->assertOk();

    $fresh = $other->fresh();
    expect($fresh->hasTwoFactorEnabled())->toBeTrue()
        ->and($fresh->two_factor_secret)->toBe(ACCOUNT_SECRET)
        ->and($fresh->two_factor_recovery_codes)->toBe($otherCodes)
        ->and($other->tokens()->count())->toBe(1);
    Notification::assertNothingSentTo($other);
});

/**
 * The "this wasn't me" link from a TwoFactorEnabled notification, as the API route.
 */
function twoFactorUndoLink(User $user): string
{
    $url = null;
    Notification::assertSentTo($user, TwoFactorEnabled::class, function (TwoFactorEnabled $notification) use (&$url, $user) {
        parse_str((string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY), $query);
        $url = "/api/account/two-factor/revert/{$query['id']}/{$query['at']}?".http_build_query(['expires' => $query['expires'], 'signature' => $query['signature']]);

        return true;
    });

    return $url;
}

it("turns it off from the email's link when someone else set it up: every device signed out, a reset link sent", function () {
    $user = User::factory()->create();
    $attacker = $user->createToken('attacker')->plainTextToken;
    $secret = $this->withToken($attacker)->postJson('/api/account/two-factor', ['current_password' => 'password'])->json('data.secret');
    $this->withToken($attacker)->postJson('/api/account/two-factor/confirm', ['code' => currentCode($secret)])->assertOk();
    $link = twoFactorUndoLink($user);

    // No login needed: the owner is locked out.
    $this->app['auth']->forgetGuards();
    $this->postJson($link)->assertOk()->assertJsonPath('message', fn (string $message) => str_contains($message, 'set a new password'));

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
    Notification::assertSentTo($user, ResetPassword::class);

    // Used up.
    $this->postJson($link)->assertForbidden();
});

it('refuses a two-factor undo link for another setup, or one tampered with', function () {
    $user = User::factory()->withTwoFactor()->create();
    $signed = fn (int $at) => URL::temporarySignedRoute('account.two-factor.revert', now()->addDay(), ['id' => $user->id, 'at' => $at], absolute: false);

    $this->postJson($signed($user->two_factor_confirmed_at->getTimestamp() - 60))->assertForbidden();
    $this->postJson(str_replace('signature=', 'signature=x', $signed($user->two_factor_confirmed_at->getTimestamp())))->assertForbidden();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('refuses to turn it on from a session signed out meanwhile', function () {
    $user = User::factory()->create();
    $token = $user->createToken('laptop');
    $secret = $this->withToken($token->plainTextToken)->postJson('/api/account/two-factor', ['current_password' => 'password'])->json('data.secret');

    // A reset or another device revokes it while this request is on its way.
    app(ConfirmTwoFactor::class)->handle($user, currentCode($secret), (function () use ($token) {
        $stale = clone $token->accessToken;
        $token->accessToken->delete();

        return $stale;
    })());
})->throws(AuthenticationException::class);

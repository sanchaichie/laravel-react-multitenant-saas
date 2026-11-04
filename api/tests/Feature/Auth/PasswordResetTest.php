<?php

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(fn () => Notification::fake());

it('emails a reset link that points at the React app', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/forgot-password', ['email' => 'Ada@Example.com'])
        ->assertOk()
        ->assertJsonPath('message', "If an account uses that email, we've sent it a link to reset the password.");

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, config('app.frontend_url').'/reset-password?token=')
            && str_contains($url, 'email=ada%40example.com');
    });
});

it('answers the same for an unknown email and sends nothing', function () {
    $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('message', "If an account uses that email, we've sent it a link to reset the password.");

    Notification::assertNothingSent();
});

it('does not send a second link within the broker throttle, and still answers the same', function () {
    $user = User::factory()->create();

    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

it('validates the email', function () {
    $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('resets the password, signs out every device and logs in with a new token', function () {
    $user = User::factory()->create();
    $oldToken = $user->createToken('laptop')->plainTextToken;
    $resetToken = Password::broker()->createToken($user);

    $response = $this->postJson('/api/reset-password', [
        'token' => $resetToken,
        'email' => $user->email,
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ])->assertOk()->assertJsonPath('user.id', $user->id);

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);

    $this->withToken($oldToken)->getJson('/api/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($response->json('token'))->getJson('/api/me')->assertOk();
});

it('marks an unverified email as verified, since the link proved it', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson('/api/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'new-secret-password',
        'password_confirmation' => 'new-secret-password',
    ])->assertOk()->assertJsonPath('user.email_verified', true);
});

it('can use a reset token only once', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $payload = ['token' => $token, 'email' => $user->email, 'password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password'];

    $this->postJson('/api/reset-password', $payload)->assertOk();
    $this->postJson('/api/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('rejects a wrong or expired token and an unknown email with the same error', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $payload = ['email' => $user->email, 'password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password'];
    $message = 'This password reset link is invalid or has expired.';

    $this->postJson('/api/reset-password', [...$payload, 'token' => 'wrong'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', $message);

    $this->postJson('/api/reset-password', [...$payload, 'token' => $token, 'email' => 'nobody@example.com'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', $message);

    $this->travel(61)->minutes();
    $this->postJson('/api/reset-password', [...$payload, 'token' => $token])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', $message);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('validates the new password', function () {
    $user = User::factory()->create();

    $this->postJson('/api/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'other',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

it('throttles forgot password requests', function () {
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
    }

    $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertTooManyRequests();
});

it('counts resets apart from logins, so failed logins do not block a reset', function () {
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong']);
    }
    $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong'])->assertTooManyRequests();

    $this->postJson('/api/forgot-password', ['email' => 'ada@example.com'])->assertOk();
});

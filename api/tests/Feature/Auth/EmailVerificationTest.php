<?php

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/** The API path and query from a verification link, as the app posts them. */
function verificationRequest(string $link): string
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return "/api/email/verify/{$query['id']}/{$query['hash']}?".http_build_query([
        'expires' => $query['expires'],
        'signature' => $query['signature'],
    ]);
}

function verificationLinkFor(User $user): string
{
    return (new class extends VerifyEmail
    {
        public function link(User $user): string
        {
            return $this->verificationUrl($user);
        }
    })->link($user);
}

it('sends a verification link to the app when registering', function () {
    Notification::fake();

    $this->postJson('/api/register', [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertCreated()->assertJsonPath('user.email_verified', false);

    $user = User::sole();
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, config('app.frontend_url').'/verify-email?');
    });
});

it('verifies the email from the link', function () {
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->postJson(verificationRequest(verificationLinkFor($user)))
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses a link for another account', function () {
    $owner = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    Sanctum::actingAs($other);

    $this->postJson(verificationRequest(verificationLinkFor($owner)))->assertForbidden();

    expect($owner->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a link for an older email address', function () {
    $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
    $link = verificationLinkFor($user);
    $user->update(['email' => 'new@example.com']);
    Sanctum::actingAs($user);

    $this->postJson(verificationRequest($link))->assertForbidden();
});

it('refuses an expired or tampered link', function () {
    $user = User::factory()->unverified()->create();
    $path = verificationRequest(verificationLinkFor($user));
    Sanctum::actingAs($user);

    $this->postJson(str_replace('signature=', 'signature=0', $path))->assertForbidden();

    $this->travel(61)->minutes();
    $this->postJson($path)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('needs a login to verify', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson(verificationRequest(verificationLinkFor($user)))->assertUnauthorized();
});

it('sends a new link on request, unless already verified', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/email/verification-notification')->assertAccepted();
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);

    $user->markEmailAsVerified();
    $this->postJson('/api/email/verification-notification')->assertOk()->assertJsonPath('email_verified', true);
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('limits resends', function () {
    Notification::fake();
    Sanctum::actingAs(User::factory()->unverified()->create());

    foreach (range(1, 6) as $_) {
        $this->postJson('/api/email/verification-notification')->assertAccepted();
    }

    $this->postJson('/api/email/verification-notification')->assertTooManyRequests();
});

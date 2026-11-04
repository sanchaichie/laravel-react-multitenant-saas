<?php

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChanged;
use App\Notifications\EmailChangeRequested;
use App\Notifications\ResetPassword;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Notification::fake());

it('updates the name', function () {
    $user = User::factory()->create(['name' => 'Ada']);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/account', ['name' => 'Ada Lovelace'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada Lovelace');

    expect($user->fresh()->name)->toBe('Ada Lovelace');
});

it('validates the name', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/account', ['name' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('needs a logged-in user for every account endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['patch', '/api/account'],
    ['put', '/api/account/email'],
    ['put', '/api/account/password'],
    ['delete', '/api/account'],
    ['get', '/api/account/devices'],
    ['delete', '/api/account/devices/1'],
]);

/**
 * The link from a ConfirmEmailChange notification, as the API route (the app posts it back).
 */
function emailChangeLink(): string
{
    $url = null;
    Notification::assertSentOnDemand(ConfirmEmailChange::class, function (ConfirmEmailChange $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url) {
        $action = $notification->toMail($notifiable)->actionUrl;
        parse_str((string) parse_url($action, PHP_URL_QUERY), $query);
        $url = "/api/account/email/confirm/{$query['id']}/{$query['hash']}?".http_build_query(['expires' => $query['expires'], 'signature' => $query['signature']]);

        return true;
    });

    return $url;
}

it('asks to change the email: nothing changes yet, the new address gets a link and the old one a notice', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $here = $user->createToken('here')->plainTextToken;
    $user->createToken('elsewhere');

    $this->withToken($here)
        ->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'old@example.com')
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.pending_email', 'new@example.com');

    expect($user->fresh())->email->toBe('old@example.com')->email_verified_at->not->toBeNull()
        ->and($user->tokens()->count())->toBe(2);
    Notification::assertSentOnDemand(ConfirmEmailChange::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'new@example.com'
        && str_starts_with($notification->toMail($notifiable)->actionUrl, config('app.frontend_url').'/confirm-email?'));
    Notification::assertSentTo($user, EmailChangeRequested::class, function (EmailChangeRequested $notification) use ($user) {
        $mail = (string) $notification->toMail($user)->render();

        return str_contains($mail, 'n***@example.com') && ! str_contains($mail, 'new@example.com');
    });
});

it('changes the email once the new address confirms: verified, other devices signed out, a notice to the old one', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $here = $user->createToken('here')->plainTextToken;
    $user->createToken('elsewhere');
    $this->withToken($here)->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'password'])->assertOk();

    $this->withToken($here)->postJson(emailChangeLink())
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.pending_email', null);

    expect($user->tokens()->pluck('name')->all())->toBe(['here']);
    Notification::assertSentOnDemand(EmailChanged::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
});

it('refuses a confirmation link for another account, a replaced or cancelled request, or a bad signature', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    Sanctum::actingAs($user);
    $this->putJson('/api/account/email', ['email' => 'first@example.com', 'current_password' => 'password']);
    $first = emailChangeLink();

    // Replaced by a newer request.
    $this->putJson('/api/account/email', ['email' => 'second@example.com', 'current_password' => 'password']);
    $this->postJson($first)->assertForbidden();

    // Someone else logged in.
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($first)->assertForbidden();

    // Tampered with.
    Sanctum::actingAs($user);
    $this->postJson(str_replace('signature=', 'signature=x', $first))->assertForbidden();

    // Cancelled.
    $this->deleteJson('/api/account/email/pending')->assertOk()->assertJsonPath('data.pending_email', null);
    expect($user->fresh())->email->toBe('old@example.com')->pending_email->toBeNull();
});

it('refuses the change if another account took the address meanwhile', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    Sanctum::actingAs($user);
    $this->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'password']);
    $link = emailChangeLink();
    User::factory()->create(['email' => 'new@example.com']);

    $this->postJson($link)->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Another account uses that email now.']);
    expect($user->fresh()->email)->toBe('old@example.com');
});

it('cancels a pending change when the password is reset through the current address', function () {
    $user = User::factory()->create(['email' => 'old@example.com', 'pending_email' => 'attacker@example.com']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/reset-password', ['token' => $token, 'email' => 'old@example.com', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])->assertOk();

    expect($user->fresh()->pending_email)->toBeNull();
});

it('needs the current password to change the email', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->email)->toBe('old@example.com');
    Notification::assertNothingSent();
});

it('rejects an email another account uses, or one that is not lowercase', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'taken@example.com', 'current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/email', ['email' => 'New@Example.com', 'current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('changes the password and signs out other devices, keeping this one', function () {
    $user = User::factory()->create();
    $here = $user->createToken('here')->plainTextToken;
    $elsewhere = $user->createToken('elsewhere')->plainTextToken;

    $this->withToken($here)
        ->putJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])
        ->assertNoContent();

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->pluck('name')->all())->toBe(['here']);

    $this->app['auth']->forgetGuards();
    $this->withToken($here)->getJson('/api/me')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($elsewhere)->getJson('/api/me')->assertUnauthorized();
});

it('needs the current password and a valid new one', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/account/password', ['current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'other'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password', 'password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('allows 10 email changes a day, counting only changes that happened', function () {
    Notification::fake();
    [$user] = userInOrganization();
    Sanctum::actingAs($user);

    // A wrong password doesn't count.
    $this->putJson('/api/account/email', ['email' => 'typo@example.com', 'current_password' => 'wrong'])->assertUnprocessable();

    foreach (range(1, 10) as $i) {
        $this->travel(61)->seconds();
        $this->putJson('/api/account/email', ['email' => "ada{$i}@example.com", 'current_password' => 'password'])->assertOk();
    }

    $this->travel(61)->seconds();
    $this->putJson('/api/account/email', ['email' => 'ada11@example.com', 'current_password' => 'password'])->assertTooManyRequests();
    expect($user->fresh()->pending_email)->toBe('ada10@example.com');
});

it('refuses your current email', function () {
    [$user] = userInOrganization();
    Sanctum::actingAs($user);

    $this->putJson('/api/account/email', ['email' => $user->email, 'current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => "That's already your email."]);
});

/**
 * The undo link from an EmailChanged notification, as the API route.
 */
function emailUndoLink(): string
{
    $url = null;
    Notification::assertSentOnDemand(EmailChanged::class, function (EmailChanged $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url) {
        parse_str((string) parse_url($notification->toMail($notifiable)->actionUrl, PHP_URL_QUERY), $query);
        $url = "/api/account/email/revert/{$query['id']}/{$query['old']}/{$query['new']}?".http_build_query(['expires' => $query['expires'], 'signature' => $query['signature']]);

        return true;
    });

    return $url;
}

it('undoes a confirmed change from the link sent to the old address: it comes back, every device is signed out, a reset link goes to it', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $attacker = $user->createToken('attacker')->plainTextToken;
    $this->withToken($attacker)->putJson('/api/account/email', ['email' => 'attacker@example.com', 'current_password' => 'password'])->assertOk();
    $this->withToken($attacker)->postJson(emailChangeLink())->assertOk();
    expect($user->fresh())->email->toBe('attacker@example.com')->previous_email->toBe('old@example.com');

    // No login needed: the owner may be locked out.
    $this->app['auth']->forgetGuards();
    $link = emailUndoLink();
    $this->postJson($link)->assertOk()->assertJsonPath('message', fn (string $message) => str_contains($message, 'set a new password'));

    expect($user->fresh())
        ->email->toBe('old@example.com')
        ->email_verified_at->not->toBeNull()
        ->previous_email->toBeNull()
        ->pending_email->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
    Notification::assertSentTo($user->fresh(), ResetPassword::class);

    // Used up.
    $this->postJson($link)->assertForbidden();
});

it('refuses an undo link that was tampered with or is for another change', function () {
    $user = User::factory()->create(['email' => 'old@example.com', 'previous_email' => 'older@example.com']);

    $signed = fn (string $old, string $new) => URL::temporarySignedRoute('account.email.revert', now()->addDay(), ['id' => $user->id, 'old' => sha1($old), 'new' => sha1($new)], absolute: false);

    $this->postJson($signed('older@example.com', 'someone-else@example.com'))->assertForbidden();
    $this->postJson(str_replace('signature=', 'signature=x', $signed('older@example.com', 'old@example.com')))->assertForbidden();
    expect($user->fresh()->email)->toBe('old@example.com');
});

it('turns two-factor off when undoing an email change if it was set up since: whoever changed it may have', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'attacker@example.com', 'previous_email' => 'old@example.com', 'email_changed_at' => now()->subHour()]);
    $link = URL::temporarySignedRoute('account.email.revert', now()->addDay(), ['id' => $user->id, 'old' => sha1('old@example.com'), 'new' => sha1('attacker@example.com')], absolute: false);

    $this->postJson($link)->assertOk()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Two-factor authentication was turned off'));

    expect($user->fresh())
        ->email->toBe('old@example.com')
        ->two_factor_secret->toBeNull()
        ->two_factor_recovery_codes->toBeNull()
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it("keeps the owner's own two-factor, set up before the change, when undoing it", function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'new@example.com',
        'previous_email' => 'old@example.com',
        'two_factor_confirmed_at' => now()->subDays(30),
        'email_changed_at' => now()->subHour(),
    ]);
    $link = URL::temporarySignedRoute('account.email.revert', now()->addDay(), ['id' => $user->id, 'old' => sha1('old@example.com'), 'new' => sha1('new@example.com')], absolute: false);

    $this->postJson($link)->assertOk()
        ->assertJsonPath('message', fn (string $message) => ! str_contains($message, 'Two-factor'));

    // The old inbox gets a reset link, but logging in still needs the owner's phone.
    expect($user->fresh())
        ->email->toBe('old@example.com')
        ->email_changed_at->toBeNull()
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('refuses another email change while the last one can still be undone, so its undo link keeps working', function () {
    $user = User::factory()->create(['email' => 'new@example.com', 'previous_email' => 'old@example.com', 'email_changed_at' => now()->subDays(6)]);
    Sanctum::actingAs($user);

    $this->putJson('/api/account/email', ['email' => 'other@example.com', 'current_password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'You changed your email recently.']);
    expect($user->fresh()->pending_email)->toBeNull();

    $this->travel(1)->days();
    $this->putJson('/api/account/email', ['email' => 'other@example.com', 'current_password' => 'password'])->assertOk();
});

it('records when an email change was confirmed', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = $user->createToken('laptop')->plainTextToken;
    $this->withToken($token)->putJson('/api/account/email', ['email' => 'new@example.com', 'current_password' => 'password'])->assertOk();
    $this->withToken($token)->postJson(emailChangeLink())->assertOk();

    expect($user->fresh()->email_changed_at)->not->toBeNull();
});

it('sends at most 3 confirmation emails a day to one address, answering the same', function () {
    foreach (range(1, 4) as $i) {
        Sanctum::actingAs(User::factory()->create());
        $this->putJson('/api/account/email', ['email' => 'target@example.com', 'current_password' => 'password'])
            ->assertOk()->assertJsonPath('data.pending_email', 'target@example.com');
    }

    Notification::assertSentOnDemandTimes(ConfirmEmailChange::class, 3);
});

it('cancels a pending email change when the password is changed', function () {
    $user = User::factory()->create(['pending_email' => 'someone@example.com']);
    Sanctum::actingAs($user);

    $this->putJson('/api/account/password', ['current_password' => 'password', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])->assertNoContent();

    expect($user->fresh()->pending_email)->toBeNull();
});

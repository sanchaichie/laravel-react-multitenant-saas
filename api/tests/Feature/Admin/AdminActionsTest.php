<?php

use App\Actions\Audit\RecordAudit;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\PlatformAuditAction;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MadeOwnerByAdmin;
use App\Notifications\ResetPassword;
use App\Notifications\TwoFactorResetByAdmin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

const REASON = 'Ticket 123: asked by phone';

beforeEach(function () {
    Notification::fake();
    $this->freezeSecond();
});

/** The member's role in the organization, read from the membership row. */
function memberRole(Organization $organization, User $member): Role
{
    return Membership::query()->where('organization_id', $organization->id)->where('user_id', $member->id)->sole()->role;
}

dataset('admin actions', [
    'reset two-factor' => fn () => ['/api/admin/users/'.User::factory()->withTwoFactor()->create()->id.'/two-factor/reset', []],
    'sign out' => fn () => ['/api/admin/users/'.User::factory()->create()->id.'/sign-out', []],
    'suspend' => fn () => ['/api/admin/users/'.User::factory()->create()->id.'/suspend', []],
    'unsuspend' => fn () => ['/api/admin/users/'.User::factory()->create(['suspended_at' => now()])->id.'/unsuspend', []],
    'make owner' => function () {
        [, $organization] = userInOrganization();
        $member = addMember($organization);

        return ["/api/admin/organizations/{$organization->id}/owners", ['user_id' => $member->id]];
    },
]);

describe('access', function () {
    it('needs a logged-in user', function (array $action) {
        [$uri, $data] = $action;

        $this->postJson($uri, [...$data, 'reason' => REASON])->assertUnauthorized();
    })->with('admin actions');

    it('answers 404 to a non-admin and changes nothing', function (array $action) {
        config(['app.debug' => false]);
        [$uri, $data] = $action;
        $token = adminToken(User::factory()->withTwoFactor()->create(), now());

        $this->withToken($token)->postJson($uri, [...$data, 'reason' => REASON])
            ->assertNotFound()
            ->assertExactJson(['message' => 'The route '.ltrim($uri, '/').' could not be found.']);

        expect(platformAuditLog())->toBeEmpty();
    })->with('admin actions');

    it('asks an admin without two-factor to turn it on', function (array $action) {
        [$uri, $data] = $action;
        $admin = User::factory()->platformAdmin()->create();

        $this->withToken(adminToken($admin, now()))->postJson($uri, [...$data, 'reason' => REASON])
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');
    })->with('admin actions');

    it('needs a fresher code than lookups', function (array $action) {
        config(['admin.two_factor_ttl_minutes' => 720, 'admin.action_two_factor_ttl_minutes' => 15]);
        [$uri, $data] = $action;
        [, $token] = platformAdmin(now()->subMinutes(16));

        // Lookups still work with this token...
        $this->withToken($token)->getJson('/api/admin/users')->assertOk();

        // ...actions ask for a new code.
        $this->withToken($token)->postJson($uri, [...$data, 'reason' => REASON])
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_reauth');

        expect(platformAuditLog())->toBeEmpty();
    })->with('admin actions');

    it('works with a code from the last few minutes', function (array $action) {
        config(['admin.action_two_factor_ttl_minutes' => 15]);
        [$uri, $data] = $action;
        [, $token] = platformAdmin(now()->subMinutes(14));

        $this->withToken($token)->postJson($uri, [...$data, 'reason' => REASON])->assertOk();
    })->with('admin actions');

    it('answers a wrong method or a plain OPTIONS with the same 404 as an unknown route, to anyone', function (?string $as) {
        config(['app.debug' => false]);
        $token = match ($as) {
            'non-admin' => adminToken(User::factory()->withTwoFactor()->create(), now()),
            'admin' => platformAdmin()[1],
            default => null,
        };
        $request = $token === null ? $this : $this->withToken($token);

        foreach (['api/admin/users/1/suspend', 'api/admin/organizations/1/owners', 'api/admin/users/1/two-factor/reset'] as $path) {
            $request->getJson('/'.$path)
                ->assertNotFound()
                ->assertExactJson(['message' => "The route {$path} could not be found."]);
        }

        foreach (['api/admin/users', 'api/admin/users/1/suspend', 'api/admin/nope'] as $path) {
            $response = $request->call('OPTIONS', '/'.$path, server: ['HTTP_ACCEPT' => 'application/json']);
            $response->assertNotFound()->assertExactJson(['message' => "The route {$path} could not be found."]);
            expect($response->headers->has('Allow'))->toBeFalse();
        }
    })->with(['guest' => null, 'non-admin' => 'non-admin', 'admin' => 'admin']);

    it('lets a reconfirmed token act again', function () {
        [$admin, $token] = platformAdmin(now()->subHour());
        $user = User::factory()->create();

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => REASON])
            ->assertJsonPath('code', 'two_factor_reauth');

        $this->withToken($token)->postJson('/api/account/two-factor/reconfirm', ['code' => adminCode()])->assertSuccessful();

        // A new request loads the token afresh (the test app keeps the guard's copy).
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => REASON])->assertOk();
    });

    it('refuses an admin with an unverified email', function (array $action) {
        [$uri, $data] = $action;
        [, $token] = platformAdmin(attributes: ['email_verified_at' => null]);

        $this->withToken($token)->postJson($uri, [...$data, 'reason' => REASON])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');
    })->with('admin actions');

    it('needs a reason of 10 to 500 characters', function (array $action, mixed $reason) {
        [$uri, $data] = $action;
        [, $token] = platformAdmin();

        $this->withToken($token)->postJson($uri, [...$data, 'reason' => $reason])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        expect(platformAuditLog())->toBeEmpty();
    })->with('admin actions')->with([
        'missing' => null,
        'too short' => 'too short',
        'too long' => str_repeat('a', 501),
    ]);
});

describe('never on platform admins', function () {
    it('refuses to act on another admin', function (string $path) {
        [, $token] = platformAdmin();
        $other = User::factory()->platformAdmin()->withTwoFactor()->create(['suspended_at' => $path === 'unsuspend' ? now() : null]);
        $other->createToken('laptop');

        $this->withToken($token)->postJson("/api/admin/users/{$other->id}/{$path}", ['reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        $other->refresh();
        expect($other->hasTwoFactorEnabled())->toBeTrue()
            ->and($other->tokens()->count())->toBe(1)
            ->and($other->is_platform_admin)->toBeTrue()
            ->and(platformAuditLog())->toBeEmpty();
    })->with(['two-factor/reset', 'sign-out', 'suspend', 'unsuspend']);

    it('refuses to act on yourself', function (string $path) {
        [$admin, $token] = platformAdmin();

        $this->withToken($token)->postJson("/api/admin/users/{$admin->id}/{$path}", ['reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        expect($admin->fresh()->isSuspended())->toBeFalse()
            ->and($admin->fresh()->hasTwoFactorEnabled())->toBeTrue()
            ->and(platformAuditLog())->toBeEmpty();
    })->with(['two-factor/reset', 'sign-out', 'suspend']);
});

describe('reset two-factor', function () {
    it('turns two-factor off, signs out every device and emails the user', function () {
        [$admin, $token] = platformAdmin();
        $user = User::factory()->withTwoFactor()->create();
        $user->createToken('laptop');
        $user->createToken('phone');
        $bystander = User::factory()->create();
        $bystander->createToken('laptop');

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/two-factor/reset", ['reason' => REASON])
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.devices_count', 0);

        $user->refresh();
        expect($user->hasTwoFactorEnabled())->toBeFalse()
            ->and($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_recovery_codes)->toBeNull()
            ->and($user->two_factor_last_used_step)->toBeNull()
            ->and($user->tokens()->count())->toBe(0)
            ->and($bystander->tokens()->count())->toBe(1);

        Notification::assertSentTo($user, TwoFactorResetByAdmin::class);

        $entry = platformAuditLog()->sole();
        expect($entry->action)->toBe(PlatformAuditAction::TwoFactorReset)
            ->and($entry->actor_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($user->id)
            ->and($entry->metadata)->toBe(['reason' => REASON])
            ->and($entry->ip_address)->toBe('127.0.0.1');
    });

    it('ends a login that was waiting for a code', function () {
        [, $token] = platformAdmin();
        $user = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com', 'password' => 'secret-password']);
        $challenge = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])->json('challenge');

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/two-factor/reset", ['reason' => REASON])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => adminCode()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['challenge']);

        // A password alone logs them in now.
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    });

    it('says when two-factor is already off', function () {
        [, $token] = platformAdmin();
        $user = User::factory()->create();

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/two-factor/reset", ['reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        Notification::assertNothingSent();
        expect(platformAuditLog())->toBeEmpty();
    });
});

describe('sign out everywhere', function () {
    it('deletes only that user\'s tokens', function () {
        [$admin, $token] = platformAdmin();
        $user = User::factory()->create();
        $userToken = $user->createToken('laptop')->plainTextToken;
        $user->createToken('phone');
        $bystander = User::factory()->create();
        $bystander->createToken('laptop');

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/sign-out", ['reason' => REASON])
            ->assertOk()
            ->assertJsonPath('data.devices_count', 0);

        expect($user->tokens()->count())->toBe(0)
            ->and($bystander->tokens()->count())->toBe(1)
            ->and($admin->tokens()->count())->toBe(1);

        $this->app['auth']->forgetGuards();
        $this->withToken($userToken)->getJson('/api/me')->assertUnauthorized();

        $entry = platformAuditLog()->sole();
        expect($entry->action)->toBe(PlatformAuditAction::UserSignedOut)
            ->and($entry->metadata)->toBe(['reason' => REASON, 'devices' => 2]);
    });
});

describe('suspend', function () {
    it('suspends the user and signs out every device', function () {
        [$admin, $token] = platformAdmin();
        $user = User::factory()->create();
        $userToken = $user->createToken('laptop')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => REASON])
            ->assertOk()
            ->assertJsonPath('data.suspended_at', now()->toJSON());

        expect($user->fresh()->isSuspended())->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);

        $this->app['auth']->forgetGuards();
        $this->withToken($userToken)->getJson('/api/me')->assertUnauthorized();

        $entry = platformAuditLog()->sole();
        expect($entry->action)->toBe(PlatformAuditAction::UserSuspended)
            ->and($entry->actor_id)->toBe($admin->id)
            ->and($entry->metadata)->toBe(['reason' => REASON]);
    });

    it('refuses a token a suspended user still has', function () {
        $user = User::factory()->create(['suspended_at' => now()]);

        $this->withToken($user->createToken('race')->plainTextToken)->getJson('/api/me')->assertUnauthorized();
    });

    it('refuses login with the right password, and gives the usual error for a wrong one', function () {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-password', 'suspended_at' => now()]);

        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', User::SUSPENDED_MESSAGE);

        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', __('auth.failed'));

        expect(User::where('email', 'ada@example.com')->first()->tokens()->count())->toBe(0);
    });

    it('ends a login that was waiting for a code', function () {
        [, $token] = platformAdmin();
        $user = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com', 'password' => 'secret-password']);
        $challenge = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])->json('challenge');

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => REASON])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/two-factor-challenge', ['challenge' => $challenge, 'code' => adminCode()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.challenge.0', User::SUSPENDED_MESSAGE);

        expect($user->tokens()->count())->toBe(0);
    });

    it('sends no password reset email', function () {
        User::factory()->create(['email' => 'ada@example.com', 'suspended_at' => now()]);

        $this->postJson('/api/forgot-password', ['email' => 'ada@example.com'])->assertSuccessful();

        Notification::assertNotSentTo(User::where('email', 'ada@example.com')->first(), ResetPassword::class);
    });

    it('refuses a reset link sent before the suspension, without changing the password', function () {
        $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-password']);
        $resetToken = Password::broker()->createToken($user);
        $user->forceFill(['suspended_at' => now()])->save();

        $this->postJson('/api/reset-password', [
            'email' => 'ada@example.com',
            'token' => $resetToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', User::SUSPENDED_MESSAGE);

        expect(Hash::check('secret-password', $user->fresh()->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);
    });

    it('says when the user is already suspended', function () {
        [, $token] = platformAdmin();
        $user = User::factory()->create(['suspended_at' => now()->subDay()]);

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/suspend", ['reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);

        expect(platformAuditLog())->toBeEmpty();
    });

    it('lets the user log in again once unsuspended', function () {
        [$admin, $token] = platformAdmin();
        $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret-password', 'suspended_at' => now()]);

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/unsuspend", ['reason' => REASON])
            ->assertOk()
            ->assertJsonPath('data.suspended_at', null);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'secret-password'])->assertOk();

        $entry = platformAuditLog()->sole();
        expect($entry->action)->toBe(PlatformAuditAction::UserUnsuspended)
            ->and($entry->actor_id)->toBe($admin->id);
    });

    it('says when the user is not suspended', function () {
        [, $token] = platformAdmin();
        $user = User::factory()->create();

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/unsuspend", ['reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);
    });

    it('shows suspension in the user list', function () {
        [, $token] = platformAdmin();
        User::factory()->create(['email' => 'ada@example.com', 'suspended_at' => now()]);

        $this->withToken($token)->getJson('/api/admin/users?q=ada@')
            ->assertOk()
            ->assertJsonPath('data.0.suspended_at', now()->toJSON());
    });
});

describe('make owner', function () {
    it('gives the member that organization\'s Owner role, never another organization\'s', function () {
        [$admin, $token] = platformAdmin();
        // B first, so its Owner role has the lower id and an unscoped lookup would find it.
        [, $organizationB] = userInOrganization(name: 'Globex');
        [, $organizationA] = userInOrganization(name: 'Acme');
        $member = addMember($organizationA);
        addMember($organizationB, DefaultRole::Member, ['email' => 'bystander@example.com']);

        $this->withToken($token)->postJson("/api/admin/organizations/{$organizationA->id}/owners", ['user_id' => $member->id, 'reason' => REASON])
            ->assertOk()
            ->assertJsonPath('data.counts.members', 2);

        expect(memberRole($organizationA, $member)->id)->toBe($organizationA->role(DefaultRole::Owner)->id)
            ->not->toBe($organizationB->role(DefaultRole::Owner)->id);
        Notification::assertSentTo($member, MadeOwnerByAdmin::class, fn ($notification) => $notification->organizationName === 'Acme');

        // Organization A's log shows a platform admin did it, without who or from where.
        $entry = auditEntries($organizationA)->last();
        expect($entry->action)->toBe(AuditAction::MemberRoleChanged)
            ->and($entry->by_platform_admin)->toBeTrue()
            ->and($entry->actor_id)->toBeNull()
            ->and($entry->ip_address)->toBeNull()
            ->and($entry->subject_id)->toBe($member->id)
            ->and($entry->changes)->toEqual(['role' => [
                'old' => ['id' => $organizationA->role(DefaultRole::Member)->id, 'name' => 'Member'],
                'new' => ['id' => $organizationA->role(DefaultRole::Owner)->id, 'name' => 'Owner'],
            ]])
            ->and($entry->metadata)->toBeNull();

        // Organization B's log is untouched (just its creation and the member joining).
        expect(auditEntries($organizationB)->where('action', AuditAction::MemberRoleChanged))->toBeEmpty();

        // The platform log has the detail.
        $platformEntry = platformAuditLog()->sole();
        expect($platformEntry->action)->toBe(PlatformAuditAction::OwnerAssigned)
            ->and($platformEntry->actor_id)->toBe($admin->id)
            ->and($platformEntry->subject_id)->toBe($organizationA->id)
            ->and($platformEntry->metadata)->toEqual([
                'reason' => REASON,
                'member_id' => $member->id,
                'member_email' => $member->email,
                'old_role' => 'Member',
            ]);

        // The admin's own memberships and current organization didn't change.
        expect($admin->fresh()->current_organization_id)->toBeNull()
            ->and($admin->organizations()->count())->toBe(0);
    });

    it('leaves the admin\'s own current organization alone', function () {
        [$admin, $token] = platformAdmin();
        [, $own] = userInOrganization(name: 'Own');
        $own->members()->attach($admin->id, ['role_id' => $own->role(DefaultRole::Member)->id]);
        $admin->forceFill(['current_organization_id' => $own->id])->save();
        [, $other] = userInOrganization(name: 'Other');
        $member = addMember($other);

        $this->withToken($token)->postJson("/api/admin/organizations/{$other->id}/owners", ['user_id' => $member->id, 'reason' => REASON])->assertOk();

        expect($admin->fresh()->current_organization_id)->toBe($own->id)
            ->and($admin->isMemberOf($other))->toBeFalse()
            ->and(memberRole($own, $admin)->is($own->role(DefaultRole::Member)))->toBeTrue()
            ->and(auditEntries($own)->where('action', AuditAction::MemberRoleChanged))->toBeEmpty();
    });

    it('answers 404 for a user who is not a member of that organization', function () {
        [, $token] = platformAdmin();
        [, $organizationA] = userInOrganization(name: 'Acme');
        [, $organizationB] = userInOrganization(name: 'Globex');
        $memberOfB = addMember($organizationB);

        $this->withToken($token)->postJson("/api/admin/organizations/{$organizationA->id}/owners", ['user_id' => $memberOfB->id, 'reason' => REASON])
            ->assertNotFound();
        $this->withToken($token)->postJson("/api/admin/organizations/{$organizationA->id}/owners", ['user_id' => 999999, 'reason' => REASON])
            ->assertNotFound();

        expect(memberRole($organizationB, $memberOfB)->is_owner)->toBeFalse()
            ->and(platformAuditLog())->toBeEmpty();
    });

    it('refuses to make the acting admin Owner', function () {
        [$admin, $token] = platformAdmin();
        [, $organization] = userInOrganization();
        $organization->members()->attach($admin->id, ['role_id' => $organization->role(DefaultRole::Member)->id]);

        $this->withToken($token)->postJson("/api/admin/organizations/{$organization->id}/owners", ['user_id' => $admin->id, 'reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        expect(memberRole($organization, $admin)->is_owner)->toBeFalse();
    });

    it('refuses to make another platform admin Owner', function () {
        [, $token] = platformAdmin();
        [, $organization] = userInOrganization();
        $otherAdmin = addMember($organization);
        $otherAdmin->forceFill(['is_platform_admin' => true])->save();

        $this->withToken($token)->postJson("/api/admin/organizations/{$organization->id}/owners", ['user_id' => $otherAdmin->id, 'reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        expect(memberRole($organization, $otherAdmin)->is_owner)->toBeFalse()
            ->and(platformAuditLog())->toBeEmpty();
        Notification::assertNothingSent();
    });

    it('does nothing when they are already Owner', function () {
        [, $token] = platformAdmin();
        [$owner, $organization] = userInOrganization();
        $before = auditEntries($organization)->count();

        $this->withToken($token)->postJson("/api/admin/organizations/{$organization->id}/owners", ['user_id' => $owner->id, 'reason' => REASON])
            ->assertOk();

        expect(auditEntries($organization)->count())->toBe($before)
            ->and(platformAuditLog())->toBeEmpty();
        Notification::assertNothingSent();
    });

    it('needs a user id', function () {
        [, $token] = platformAdmin();
        [, $organization] = userInOrganization();

        $this->withToken($token)->postJson("/api/admin/organizations/{$organization->id}/owners", ['reason' => REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    });

    it('shows "by a platform admin" in the organization\'s audit log, without the reason', function () {
        [, $token] = platformAdmin();
        [$owner, $organization] = userInOrganization();
        $member = addMember($organization);
        inOrganization($organization, fn () => app(RecordAudit::class)->handle(AuditAction::AiBudgetUpdated));

        $this->withToken($token)->postJson("/api/admin/organizations/{$organization->id}/owners", ['user_id' => $member->id, 'reason' => REASON])->assertOk();

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($owner->createToken('test')->plainTextToken)->getJson('/api/audit-logs')->assertOk();

        $entry = collect($response->json('data'))->firstWhere('action', 'member.role_changed');
        expect($entry['by_platform_admin'])->toBeTrue()
            ->and($entry['actor'])->toBeNull()
            ->and($entry['ip_address'])->toBeNull()
            ->and($response->getContent())->not->toContain(REASON);

        expect(collect($response->json('data'))->firstWhere('action', 'ai_budget.updated')['by_platform_admin'])->toBeFalse();
    });
});

<?php

use App\Actions\Admin\RecordImpersonatedView;
use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\PlatformAuditAction;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ViewedBySupport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

const VIEW_AS_REASON = 'Ticket 456: billing page looks empty';

beforeEach(function () {
    Notification::fake();
    $this->freezeSecond();

    [$this->user, $this->acme] = userInOrganization(DefaultRole::Member, 'Acme');
    $this->globex = Organization::factory()->withMember($this->user, DefaultRole::Admin)->create(['name' => 'Globex']);
    $this->task = inOrganization($this->acme, fn () => Task::factory()->createdBy($this->user)->create([
        'title' => 'Launch plan',
        'description' => 'Secret launch details',
    ]));
    [$this->admin, $this->adminToken] = platformAdmin();
});

/**
 * Send the next request with $token, as a new request would (Sanctum keeps the
 * user of the last token otherwise).
 */
function withFreshToken(string $token): mixed
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

/**
 * The organization's "Platform admin viewed as" entries.
 */
function supportViewsOf(Organization $organization): Collection
{
    return auditEntries($organization, AuditAction::SupportViewedAs);
}

/** Start a "view as user" session through the API; returns its token. */
function startViewingAs(User $user, string $adminToken): string
{
    return withFreshToken($adminToken)
        ->postJson("/api/admin/users/{$user->id}/impersonate", ['reason' => VIEW_AS_REASON])
        ->assertCreated()
        ->json('token');
}

/**
 * The route's URI with a value for each parameter that its pattern accepts (an id,
 * a 48-character invitation token, a 40-character hash), so the router finds it.
 */
function routeUriWithSampleParameters(RoutingRoute $route): string
{
    return preg_replace_callback('/\{(\w+)\??\}/', function (array $match) use ($route) {
        $pattern = $route->wheres[$match[1]] ?? null;
        foreach (['1', str_repeat('a', 40), str_repeat('a', 48)] as $sample) {
            if ($pattern === null || preg_match('/^(?:'.$pattern.')$/', $sample) === 1) {
                return $sample;
            }
        }

        throw new RuntimeException("No sample value for {$match[1]} in {$route->uri()}");
    }, $route->uri());
}

function sessionToken(User $user): PersonalAccessToken
{
    return PersonalAccessToken::query()->where('tokenable_id', $user->id)->whereNotNull('impersonator_id')->sole();
}

describe('starting', function () {
    it('needs a logged-in user', function () {
        $this->postJson("/api/admin/users/{$this->user->id}/impersonate", ['reason' => VIEW_AS_REASON])->assertUnauthorized();
    });

    it('answers 404 to a non-admin', function () {
        config(['app.debug' => false]);
        $token = adminToken(User::factory()->withTwoFactor()->create(), now());

        withFreshToken($token)->postJson("/api/admin/users/{$this->user->id}/impersonate", ['reason' => VIEW_AS_REASON])
            ->assertNotFound()
            ->assertExactJson(['message' => "The route api/admin/users/{$this->user->id}/impersonate could not be found."]);
    });

    it('asks for two-factor, a fresh code and a verified email', function (Closure $admin, string $code) {
        $token = $admin();

        withFreshToken($token)->postJson("/api/admin/users/{$this->user->id}/impersonate", ['reason' => VIEW_AS_REASON])
            ->assertForbidden()
            ->assertJsonPath('code', $code);

        expect(PersonalAccessToken::query()->whereNotNull('impersonator_id')->exists())->toBeFalse();
    })->with([
        'no two-factor' => [fn () => adminToken(User::factory()->platformAdmin()->create(), now()), 'two_factor_required'],
        'stale code' => [fn () => platformAdmin(now()->subMinutes(16))[1], 'two_factor_reauth'],
        'unverified' => [fn () => platformAdmin(attributes: ['email_verified_at' => null])[1], 'email_unverified'],
    ]);

    it('needs a reason', function (mixed $reason) {
        withFreshToken($this->adminToken)->postJson("/api/admin/users/{$this->user->id}/impersonate", ['reason' => $reason])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    })->with([null, 'too short', str_repeat('a', 501)]);

    it('refuses platform admins (yourself too), suspended users and users in no organization', function (Closure $target) {
        $target = $target($this);

        withFreshToken($this->adminToken)->postJson("/api/admin/users/{$target->id}/impersonate", ['reason' => VIEW_AS_REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        expect(PersonalAccessToken::query()->whereNotNull('impersonator_id')->exists())->toBeFalse()
            ->and(platformAuditLog())->toBeEmpty();
        Notification::assertNothingSent();
    })->with([
        'yourself' => fn ($test) => $test->admin,
        'another admin' => fn () => User::factory()->platformAdmin()->withTwoFactor()->create(),
        'suspended' => fn () => User::factory()->create(['suspended_at' => now()]),
        'no organization' => fn () => User::factory()->create(),
    ]);

    it('issues an expiring token for the user that names the admin', function () {
        config(['admin.impersonation_minutes' => 30]);

        $response = withFreshToken($this->adminToken)
            ->postJson("/api/admin/users/{$this->user->id}/impersonate", ['reason' => VIEW_AS_REASON])
            ->assertCreated()
            ->assertJsonStructure(['token', 'expires_at']);

        $token = sessionToken($this->user);
        expect($token->impersonator_id)->toBe($this->admin->id)
            ->and($token->organization_id)->toBe($this->acme->id)
            ->and($token->expires_at->equalTo(now()->addMinutes(30)))->toBeTrue()
            ->and($token->getAttribute('two_factor_at'))->toBeNull()
            ->and($response->json('expires_at'))->toBe(now()->addMinutes(30)->toJSON());
    });

    it('emails the user without the reason', function () {
        startViewingAs($this->user, $this->adminToken);

        Notification::assertSentTo($this->user, ViewedBySupport::class, function (ViewedBySupport $notification) {
            $mail = $notification->toMail($this->user);

            return ! str_contains(implode(' ', [$mail->subject, ...$mail->introLines, ...$mail->outroLines]), VIEW_AS_REASON);
        });
    });

    it('logs the admin and reason in the platform log, and "Platform admin" in the organization', function () {
        startViewingAs($this->user, $this->adminToken);

        $entry = platformAuditLog()->sole();
        expect($entry->action)->toBe(PlatformAuditAction::ImpersonationStarted)
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->ip_address)->toBe('127.0.0.1')
            ->and($entry->subject_id)->toBe($this->user->id)
            ->and($entry->metadata['reason'])->toBe(VIEW_AS_REASON);

        $audit = supportViewsOf($this->acme)->sole();
        expect($audit->by_platform_admin)->toBeTrue()
            ->and($audit->actor_id)->toBeNull()
            ->and($audit->ip_address)->toBeNull()
            ->and($audit->subject_id)->toBe($this->user->id)
            ->and(supportViewsOf($this->globex))->toBeEmpty();
    });

    it('starts in the first organization when the user has no current one', function () {
        $this->user->forceFill(['current_organization_id' => null])->save();

        startViewingAs($this->user, $this->adminToken);

        expect(sessionToken($this->user)->organization_id)->toBe($this->acme->id);
    });
});

it('starts in a remaining organization when the user\'s current one is gone', function () {
    $this->acme->members()->detach($this->user->id);

    startViewingAs($this->user, $this->adminToken);

    expect(sessionToken($this->user)->organization_id)->toBe($this->globex->id);
});

describe('the session', function () {
    beforeEach(function () {
        $this->token = startViewingAs($this->user, $this->adminToken);
    });

    it('shows the app as the user, naming the admin but not their email', function () {
        withFreshToken($this->token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.is_platform_admin', false)
            ->assertJsonPath('data.impersonated_by.name', $this->admin->name)
            ->assertJsonPath('data.impersonated_by.expires_at', sessionToken($this->user)->expires_at->toJSON())
            ->assertJsonPath('data.current_organization.id', $this->acme->id)
            ->assertDontSee($this->admin->email);
    });

    it('refuses every write route except logout and switching', function () {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('impersonation', $route->gatherMiddleware(), true))
            ->reject(fn (RoutingRoute $route) => in_array($route->getName(), ['logout', 'organizations.switch'], true))
            ->flatMap(fn (RoutingRoute $route) => collect($route->methods())
                ->reject(fn (string $method) => in_array($method, ['GET', 'HEAD', 'OPTIONS'], true))
                ->map(fn (string $method) => [$method, routeUriWithSampleParameters($route)]));

        expect($routes->count())->toBeGreaterThan(30);
        $before = User::query()->orderBy('id')->get()->toJson().Task::withoutGlobalScopes()->get()->toJson();
        $auditCount = AuditLog::withoutGlobalScopes()->count();

        foreach ($routes as [$method, $uri]) {
            withFreshToken($this->token)->json($method, '/'.$uri, ['name' => 'Changed', 'reason' => VIEW_AS_REASON])
                ->assertForbidden()
                ->assertJsonPath('code', 'impersonation_read_only');
        }

        expect(User::query()->orderBy('id')->get()->toJson().Task::withoutGlobalScopes()->get()->toJson())->toBe($before)
            ->and(AuditLog::withoutGlobalScopes()->count())->toBe($auditCount);
    });

    it('hides task descriptions, chats and summaries, but not titles', function () {
        withFreshToken($this->token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Launch plan')
            ->assertJsonPath('data.0.description', null)
            ->assertJsonPath('data.0.content_hidden', true)
            ->assertDontSee('Secret launch details');
        withFreshToken($this->token)->getJson("/api/tasks/{$this->task->id}")
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.content_hidden', true);

        foreach (['summary', 'chat'] as $path) {
            withFreshToken($this->token)->getJson("/api/tasks/{$this->task->id}/{$path}")
                ->assertForbidden()
                ->assertJsonPath('code', 'impersonation_hidden');
        }
    });

    it('searches titles only, so a search cannot tell what a description says', function () {
        withFreshToken($this->token)->getJson('/api/tasks?search=secret')->assertOk()->assertJsonCount(0, 'data');
        withFreshToken($this->token)->getJson('/api/tasks?search=launch')->assertOk()->assertJsonCount(1, 'data');

        $own = $this->user->createToken('own')->plainTextToken;
        withFreshToken($own)->getJson('/api/tasks?search=secret')->assertOk()->assertJsonCount(1, 'data');
    });

    it('still shows task content to the user themselves', function () {
        $own = $this->user->createToken('own')->plainTextToken;

        withFreshToken($own)->getJson("/api/tasks/{$this->task->id}")
            ->assertJsonPath('data.description', 'Secret launch details')
            ->assertJsonPath('data.content_hidden', false);
        withFreshToken($own)->getJson("/api/tasks/{$this->task->id}/chat")->assertOk();
        withFreshToken($own)->getJson('/api/account/devices')->assertOk();
    });

    it('hides task changes and IP addresses in the audit log', function () {
        $this->acme->members()->updateExistingPivot($this->user->id, ['role_id' => $this->acme->role(DefaultRole::Owner)->id]);
        inOrganization($this->acme, fn () => $this->task->update(['description' => 'New secret']));

        $entries = collect(withFreshToken($this->token)->getJson('/api/audit-logs')->assertOk()->json('data'));
        $taskEntry = $entries->firstWhere('action', 'task.updated');

        expect($taskEntry['subject']['label'])->toBe('Launch plan')
            ->and($entries->every(fn (array $entry) => ! array_key_exists('ip_address', $entry)))->toBeTrue()
            ->and($taskEntry['changes'])->toBe([])
            ->and(json_encode($entries))->not->toContain('secret');
    });

    it('hides the user\'s devices and invoices', function () {
        $this->acme->members()->updateExistingPivot($this->user->id, ['role_id' => $this->acme->role(DefaultRole::Owner)->id]);

        foreach (['/api/account/devices', '/api/billing/invoices'] as $uri) {
            withFreshToken($this->token)->getJson($uri)
                ->assertForbidden()
                ->assertJsonPath('code', 'impersonation_hidden');
        }
        expect($this->billing->invoiceRequests())->toBeEmpty();
    });

    it('hides webhook URLs and deliveries, which hold task content', function () {
        $this->acme->members()->updateExistingPivot($this->user->id, ['role_id' => $this->acme->role(DefaultRole::Owner)->id]);
        $endpoint = createWebhook($this->acme, ['url' => 'https://hooks.example.com/T0KEN']);

        withFreshToken($this->token)->getJson("/api/webhooks/{$endpoint->id}")
            ->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.url_hidden', true)
            ->assertJsonPath('data.host', 'hooks.example.com')
            ->assertDontSee('T0KEN');
        withFreshToken($this->token)->getJson("/api/webhooks/{$endpoint->id}/deliveries")
            ->assertForbidden()
            ->assertJsonPath('code', 'impersonation_hidden');
        withFreshToken($this->token)->postJson("/api/webhooks/{$endpoint->id}/test")
            ->assertForbidden()
            ->assertJsonPath('code', 'impersonation_read_only');
    });

    it('never reaches admin routes', function () {
        config(['app.debug' => false]);

        withFreshToken($this->token)->getJson('/api/admin/users')->assertNotFound();
    });

    it('switches only the session, recording each organization once', function () {
        withFreshToken($this->token)->postJson("/api/organizations/{$this->globex->id}/switch")->assertOk();
        withFreshToken($this->token)->getJson('/api/me')->assertJsonPath('data.current_organization.id', $this->globex->id);
        withFreshToken($this->token)->postJson("/api/organizations/{$this->acme->id}/switch")->assertOk();
        withFreshToken($this->token)->postJson("/api/organizations/{$this->globex->id}/switch")->assertOk();

        expect($this->user->fresh()->current_organization_id)->toBe($this->acme->id)
            ->and(supportViewsOf($this->acme))->toHaveCount(1)
            ->and(supportViewsOf($this->globex)->sole()->by_platform_admin)->toBeTrue();
    });

    it('can\'t switch to or read an organization the user isn\'t in', function () {
        [, $initech] = userInOrganization(name: 'Initech');
        $theirs = inOrganization($initech, fn () => Task::factory()->create());

        withFreshToken($this->token)->postJson("/api/organizations/{$initech->id}/switch")->assertNotFound();
        withFreshToken($this->token)->getJson("/api/tasks/{$theirs->id}")->assertNotFound();

        expect(sessionToken($this->user)->organization_id)->toBe($this->acme->id)
            ->and(supportViewsOf($initech))->toBeEmpty();
    });

    it('reads only the organization the session is in, among the user\'s own', function () {
        $globexTask = inOrganization($this->globex, fn () => Task::factory()->create(['title' => 'Globex thing']));

        withFreshToken($this->token)->getJson("/api/tasks/{$globexTask->id}")->assertNotFound();
        withFreshToken($this->token)->getJson('/api/tasks')->assertJsonMissing(['title' => 'Globex thing']);

        withFreshToken($this->token)->postJson("/api/organizations/{$this->globex->id}/switch")->assertOk();

        withFreshToken($this->token)->getJson("/api/tasks/{$this->task->id}")->assertNotFound();
        withFreshToken($this->token)->getJson('/api/tasks')
            ->assertJsonPath('data.0.title', 'Globex thing')
            ->assertJsonCount(1, 'data');
    });

    it('refuses a switch the user lost access to after it was checked, recording nothing', function () {
        $this->globex->members()->detach($this->user->id);
        $token = sessionToken($this->user);

        expect(fn () => app(RecordImpersonatedView::class)->handle($token, $this->globex, $this->user))
            ->toThrow(ModelNotFoundException::class);

        expect(sessionToken($this->user)->organization_id)->toBe($this->acme->id)
            ->and(supportViewsOf($this->globex))->toBeEmpty();
    });

    it('fails closed when the user leaves the organization the session is in', function () {
        $this->acme->members()->detach($this->user->id);

        withFreshToken($this->token)->getJson('/api/tasks')->assertForbidden();
        withFreshToken($this->token)->getJson('/api/me')->assertJsonPath('data.current_organization', null);
    });

    it('keeps the session and the user\'s own token apart', function () {
        $own = $this->user->createToken('own')->plainTextToken;

        withFreshToken($this->token)->postJson("/api/organizations/{$this->globex->id}/switch")->assertOk();
        withFreshToken($own)->getJson('/api/me')->assertJsonPath('data.current_organization.id', $this->acme->id);

        withFreshToken($own)->postJson("/api/organizations/{$this->acme->id}/switch")->assertOk();
        withFreshToken($this->token)->getJson('/api/me')->assertJsonPath('data.current_organization.id', $this->globex->id);
    });

    it('leaves the admin\'s own organization alone', function () {
        $adminOrganization = Organization::factory()->withMember($this->admin, DefaultRole::Owner)->create();
        $this->admin->forceFill(['current_organization_id' => $adminOrganization->id])->save();

        withFreshToken($this->token)->postJson("/api/organizations/{$this->globex->id}/switch")->assertOk();
        withFreshToken($this->token)->postJson('/api/logout')->assertNoContent();

        expect($this->admin->fresh()->current_organization_id)->toBe($adminOrganization->id)
            ->and(supportViewsOf($adminOrganization))->toBeEmpty();
    });

    it('ends with logout, logged as the admin\'s, leaving every other token alone', function () {
        $own = $this->user->createToken('own')->plainTextToken;

        withFreshToken($this->token)->postJson('/api/logout')->assertNoContent();

        expect(PersonalAccessToken::query()->whereNotNull('impersonator_id')->exists())->toBeFalse();
        $ended = platformAuditLog()->last();
        expect($ended->action)->toBe(PlatformAuditAction::ImpersonationEnded)
            ->and($ended->actor_id)->toBe($this->admin->id)
            ->and($ended->subject_id)->toBe($this->user->id);

        withFreshToken($this->token)->getJson('/api/me')->assertUnauthorized();
        withFreshToken($own)->getJson('/api/me')->assertOk();
        withFreshToken($this->adminToken)->getJson('/api/admin/users')->assertOk();
    });

    it('expires', function () {
        $this->travel(config('admin.impersonation_minutes') + 1)->minutes();

        withFreshToken($this->token)->getJson('/api/me')->assertUnauthorized();
    });

    it('ends once the admin or user changes', function (Closure $change) {
        $change($this);

        withFreshToken($this->token)->getJson('/api/me')->assertUnauthorized();
    })->with([
        'admin revoked' => fn ($test) => $test->admin->forceFill(['is_platform_admin' => false])->save(),
        'admin two-factor off' => fn ($test) => $test->admin->forceFill(['two_factor_confirmed_at' => null])->save(),
        'admin suspended' => fn ($test) => $test->admin->forceFill(['suspended_at' => now()])->save(),
        'user suspended' => fn ($test) => $test->user->forceFill(['suspended_at' => now()])->save(),
        'user made admin' => fn ($test) => $test->user->forceFill(['is_platform_admin' => true])->save(),
    ]);

    it('isn\'t one of the user\'s devices, and doesn\'t count as them being active', function () {
        $own = $this->user->createToken('own');
        withFreshToken($this->token)->getJson('/api/me')->assertOk();
        $session = sessionToken($this->user);
        expect($session->last_used_at)->not->toBeNull();

        $detail = withFreshToken($this->adminToken)->getJson("/api/admin/users/{$this->user->id}")->assertOk();
        expect($detail->json('data.devices_count'))->toBe(1)
            ->and($detail->json('data.last_active_at'))->toBeNull();

        $devices = withFreshToken($own->plainTextToken)->getJson('/api/account/devices')->assertOk()->json('data');
        expect(collect($devices)->pluck('id')->all())->toBe([$own->accessToken->id]);
        withFreshToken($own->plainTextToken)->deleteJson("/api/account/devices/{$session->id}")->assertNotFound();
        expect($session->fresh())->not->toBeNull();
    });

    it('is ended by signing the user out everywhere', function () {
        [, $actionToken] = platformAdmin();
        withFreshToken($actionToken)->postJson("/api/admin/users/{$this->user->id}/sign-out", ['reason' => VIEW_AS_REASON])->assertOk();

        withFreshToken($this->token)->getJson('/api/me')->assertUnauthorized();
    });
});

it('prunes expired sessions daily', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('sanctum:prune-expired')->assertSuccessful();
});

it('doesn\'t write to the database on reads, apart from the token\'s last use', function () {
    $token = startViewingAs($this->user, $this->adminToken);
    $auditCount = AuditLog::withoutGlobalScopes()->count();

    DB::enableQueryLog();
    withFreshToken($token)->getJson('/api/tasks')->assertOk();
    withFreshToken($token)->getJson('/api/members')->assertOk();
    withFreshToken($token)->getJson('/api/me')->assertOk();

    $writes = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1)
        ->reject(fn (string $sql) => str_contains($sql, 'personal_access_tokens') && str_contains($sql, 'last_used_at'));

    expect($writes->all())->toBe([])
        ->and(AuditLog::withoutGlobalScopes()->count())->toBe($auditCount);
});

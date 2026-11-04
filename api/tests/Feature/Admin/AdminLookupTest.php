<?php

use App\Enums\DefaultRole;
use App\Enums\PlatformAuditAction;
use App\Models\AuditLog;
use App\Models\PlatformAuditLog;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    [$this->admin, $this->token] = platformAdmin(attributes: ['name' => 'Root Admin', 'email' => 'root@example.com']);
});

describe('users', function () {
    it('lists everyone, newest first, with their organization count', function () {
        [$owner] = userInOrganization(name: 'Acme');

        $this->withToken($this->token)->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $owner->id)
            ->assertJsonPath('data.0.organizations_count', 1)
            ->assertJsonPath('data.1.id', $this->admin->id)
            ->assertJsonPath('data.1.is_platform_admin', true)
            ->assertJsonPath('data.1.two_factor_enabled', true)
            ->assertJsonPath('meta.per_page', 25);
    });

    it('searches name and email, ignoring case', function () {
        User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@analytical.test']);
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@cobol.test']);

        $this->withToken($this->token)->getJson('/api/admin/users?q=LOVE')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ada Lovelace');
        $this->withToken($this->token)->getJson('/api/admin/users?q=cobol')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Grace Hopper');
    });

    it('takes %, _ and \\ literally', function () {
        User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        User::factory()->create(['name' => '100% Bob', 'email' => 'b_ob@example.com']);
        User::factory()->create(['name' => 'Back\\slash', 'email' => 'cy@example.com']);

        $this->withToken($this->token)->getJson('/api/admin/users?q=%25')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% Bob');
        $this->withToken($this->token)->getJson('/api/admin/users?q=_')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% Bob');
        $this->withToken($this->token)->getJson('/api/admin/users?q=%5C')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Back\\slash');
    });

    it('pages 25 at a time', function () {
        User::factory()->count(26)->create();

        $this->withToken($this->token)->getJson('/api/admin/users?page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 27);
    });

    it('refuses a search longer than 100 characters', function () {
        $this->withToken($this->token)->getJson('/api/admin/users?q='.str_repeat('a', 101))
            ->assertUnprocessable()->assertJsonValidationErrors(['q']);
    });

    it('shows one user with their organizations and roles, and logs the view', function () {
        [$user] = userInOrganization(name: 'Acme');
        [, $globex] = userInOrganization(name: 'Globex');
        $globex->members()->attach($user, ['role_id' => $globex->role(DefaultRole::Member)->id]);
        $user->createToken('laptop')->accessToken->forceFill(['last_used_at' => '2026-10-20 08:30:00'])->save();

        $this->withToken($this->token)->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.devices_count', 1)
            ->assertJsonPath('data.last_active_at', '2026-10-20T08:30:00.000000Z')
            ->assertJsonPath('data.organizations.0.name', 'Acme')
            ->assertJsonPath('data.organizations.0.role.name', 'Owner')
            ->assertJsonPath('data.organizations.0.role.is_owner', true)
            ->assertJsonPath('data.organizations.1.id', $globex->id)
            ->assertJsonPath('data.organizations.1.role.name', 'Member')
            ->assertJsonPath('data.organizations.1.role.id', $globex->role(DefaultRole::Member)->id);

        $entry = platformAuditLog()->sole();
        expect($entry->action)->toBe(PlatformAuditAction::UserViewed)
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->subject_type)->toBe('user')
            ->and($entry->subject_id)->toBe($user->id)
            ->and($entry->subject_label)->toBe($user->email)
            ->and($entry->ip_address)->toBe('127.0.0.1');
    });

    it('answers 404 for a user that does not exist', function () {
        $this->withToken($this->token)->getJson('/api/admin/users/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    });
});

describe('organizations', function () {
    it('lists organizations with member count and plan, and searches by name', function () {
        [, $acme] = userInOrganization(name: 'Acme');
        addMember($acme);
        [, $globex] = userInOrganization(name: 'Globex');
        subscribe($globex);

        $this->withToken($this->token)->getJson('/api/admin/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Globex')
            ->assertJsonPath('data.0.plan', 'pro')
            ->assertJsonPath('data.1.members_count', 2)
            ->assertJsonPath('data.1.plan', 'free');

        $this->withToken($this->token)->getJson('/api/admin/organizations?q=acm')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $acme->id);
    });

    it("shows organization B's plan, budget, counts and members, not A's, and logs the view", function () {
        // The admin works in organization A; B is looked up.
        [, $a] = userInOrganization(name: 'A');
        $a->members()->attach($this->admin, ['role_id' => createRole($a, 'A helper', [])->id]);
        $this->admin->forceFill(['current_organization_id' => $a->id])->save();
        inOrganization($a, fn () => Task::factory()->count(3)->create());
        createInvitation($a, 'a-invite@example.com');
        recordAiUsage($a, 9.0);

        [$bOwner, $b] = userInOrganization(name: 'B');
        $bMember = addMember($b, DefaultRole::Admin);
        inOrganization($b, fn () => Task::factory()->create());
        createInvitation($b, 'b-invite@example.com');
        createInvitation($b, 'b-old@example.com', attributes: ['expires_at' => now()->subDay()]);
        recordAiUsage($b, 1.25);
        subscribe($b);

        $this->withToken($this->token)->getJson("/api/admin/organizations/{$b->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'B')
            ->assertJsonPath('data.plan.key', 'pro')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.ai_budget.spent_usd', 1.25)
            ->assertJsonPath('data.counts', ['members' => 2, 'tasks' => 1, 'pending_invitations' => 1])
            ->assertJsonCount(2, 'data.members')
            ->assertJsonPath('data.members.0.id', $bOwner->id)
            ->assertJsonPath('data.members.0.role.is_owner', true)
            ->assertJsonPath('data.members.1.id', $bMember->id)
            ->assertJsonPath('data.members.1.role.name', 'Admin')
            ->assertJsonPath('data.members.1.role.id', $b->role(DefaultRole::Admin)->id);

        // Looking didn't move the admin into B or change their membership.
        expect($this->admin->fresh()->current_organization_id)->toBe($a->id)
            ->and($this->admin->isMemberOf($b))->toBeFalse();

        $entry = platformAuditLog()->sole();
        expect($entry->action)->toBe(PlatformAuditAction::OrganizationViewed)
            ->and($entry->subject_id)->toBe($b->id)
            ->and($entry->subject_label)->toBe('B');
    });

    it('answers 404 for an organization that does not exist', function () {
        $this->withToken($this->token)->getJson('/api/admin/organizations/999999')->assertNotFound();
    });
});

it('never sends secrets or task content', function () {
    $user = User::factory()->withTwoFactor()->create();
    [, $organization] = userInOrganization(name: 'Acme');
    $organization->members()->attach($user, ['role_id' => $organization->role(DefaultRole::Member)->id]);
    $organization->forceFill(['stripe_id' => 'cus_secret123', 'pm_last_four' => '4242'])->save();
    inOrganization($organization, fn () => Task::factory()->create(['title' => 'Secret plan', 'description' => 'Top secret content']));
    $plainToken = $user->createToken('laptop')->plainTextToken;

    $bodies = collect([
        "/api/admin/users/{$user->id}",
        '/api/admin/users',
        "/api/admin/organizations/{$organization->id}",
        '/api/admin/organizations',
        '/api/admin/audit-log',
    ])->map(fn (string $uri) => $this->withToken($this->token)->getJson($uri)->assertOk()->getContent())->implode("\n");

    expect($bodies)
        ->not->toContain('JBSWY3DPEHPK3PXP')
        ->not->toContain('two_factor_secret')
        ->not->toContain('recovery_codes')
        ->not->toContain('cus_secret123')
        ->not->toContain('4242')
        ->not->toContain('stripe_id')
        ->not->toContain(explode('|', $plainToken)[1])
        ->not->toContain('password')
        ->not->toContain('Secret plan')
        ->not->toContain('Top secret content');
});

describe('platform log', function () {
    it('lists entries newest first with the admin and IP', function () {
        [$user] = userInOrganization();
        $this->withToken($this->token)->getJson("/api/admin/users/{$user->id}")->assertOk();
        $this->withToken($this->token)->getJson("/api/admin/users/{$this->admin->id}")->assertOk();

        $this->withToken($this->token)->getJson('/api/admin/audit-log')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subject.id', $this->admin->id)
            ->assertJsonPath('data.0.action', 'admin.user_viewed')
            ->assertJsonPath('data.0.action_label', 'Viewed a user')
            ->assertJsonPath('data.0.actor.email', 'root@example.com')
            ->assertJsonPath('data.0.ip_address', '127.0.0.1')
            ->assertJsonPath('data.1.subject.id', $user->id);
    });

    it('pages with a cursor', function () {
        [$user] = userInOrganization();
        foreach (range(1, 26) as $ignored) {
            $this->withToken($this->token)->getJson("/api/admin/users/{$user->id}")->assertOk();
        }

        $first = $this->withToken($this->token)->getJson('/api/admin/audit-log')->assertOk()->assertJsonCount(25, 'data');
        $cursor = $first->json('meta.next_cursor');

        $this->withToken($this->token)->getJson('/api/admin/audit-log?cursor='.$cursor)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    });

    it('does not log list searches', function () {
        $this->withToken($this->token)->getJson('/api/admin/users?q=ada@example.com')->assertOk();
        $this->withToken($this->token)->getJson('/api/admin/organizations?q=Acme')->assertOk();

        expect(PlatformAuditLog::count())->toBe(0);
    });

    it("never writes to an organization's own audit log", function () {
        [, $organization] = userInOrganization();
        $before = AuditLog::withoutGlobalScope(OrganizationScope::class)->count();

        $this->withToken($this->token)->getJson("/api/admin/organizations/{$organization->id}")->assertOk();

        expect(AuditLog::withoutGlobalScope(OrganizationScope::class)->count())->toBe($before);
    });

    it('cannot be changed or deleted one by one', function () {
        [$user] = userInOrganization();
        $this->withToken($this->token)->getJson("/api/admin/users/{$user->id}")->assertOk();
        $entry = PlatformAuditLog::sole();

        expect(fn () => $entry->forceFill(['subject_label' => 'x'])->save())->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class);
    });

    it('is pruned after the retention period', function () {
        config(['audit.retention_days' => 30]);
        [$user] = userInOrganization();

        $this->travel(-31)->days();
        $this->withToken($this->token)->getJson("/api/admin/users/{$user->id}")->assertOk();
        $this->travelBack();
        $this->withToken($this->token)->getJson("/api/admin/users/{$user->id}")->assertOk();

        $this->artisan('model:prune', ['--model' => [PlatformAuditLog::class]])->assertSuccessful();

        expect(PlatformAuditLog::count())->toBe(1);
    });
});

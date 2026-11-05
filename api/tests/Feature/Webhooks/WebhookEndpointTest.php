<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    [$this->owner, $this->acme] = userInOrganization(DefaultRole::Owner, 'Acme');
    Sanctum::actingAs($this->owner);
});

function webhookInput(array $overrides = []): array
{
    return [
        'url' => 'https://hooks.example.com/acme',
        'description' => 'CRM sync',
        'events' => ['task.created', 'task.deleted'],
        ...$overrides,
    ];
}

describe('creating', function () {
    it('creates a webhook and shows its secret once', function () {
        $response = $this->postJson('/api/webhooks', webhookInput())
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://hooks.example.com/acme')
            ->assertJsonPath('data.host', 'hooks.example.com')
            ->assertJsonPath('data.events', ['task.created', 'task.deleted'])
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.last_delivery', null);

        $secret = $response->json('meta.secret');
        $endpoint = inOrganization($this->acme, fn () => WebhookEndpoint::query()->sole());

        expect($secret)->toMatch('/^whsec_[0-9a-f]{64}$/')
            ->and($endpoint->secret)->toBe($secret)
            ->and(DB::table('webhook_endpoints')->value('secret'))->not->toContain($secret);

        $this->getJson("/api/webhooks/{$endpoint->id}")->assertOk()->assertJsonMissingPath('meta.secret')->assertDontSee($secret);
        $this->getJson('/api/webhooks')->assertOk()->assertJsonCount(1, 'data')->assertDontSee($secret)->assertJsonPath('meta.max', 5);
    });

    it('records the host only in the audit log, never the full URL or the secret', function () {
        $secret = $this->postJson('/api/webhooks', webhookInput(['url' => 'https://hooks.example.com/T0KEN']))->json('meta.secret');

        $entry = auditEntries($this->acme, AuditAction::WebhookCreated)->sole();

        expect($entry->subject_label)->toBe('hooks.example.com')
            ->and($entry->changes['url'])->toEqual(['old' => null, 'new' => 'hooks.example.com'])
            ->and(json_encode($entry->changes))->not->toContain('T0KEN')->not->toContain($secret);
    });

    it('refuses a 6th webhook', function () {
        foreach (range(1, 5) as $i) {
            createWebhook($this->acme);
        }

        $this->postJson('/api/webhooks', webhookInput())->assertUnprocessable()->assertJsonValidationErrors('url');
    });

    it('validates the events', function (mixed $events) {
        $this->postJson('/api/webhooks', webhookInput(['events' => $events]))->assertUnprocessable()->assertJsonValidationErrors('events');
    })->with([
        'none' => [[]],
        'missing' => [null],
    ]);

    it('refuses unknown or repeated events', function (array $events) {
        $this->postJson('/api/webhooks', webhookInput(['events' => $events]))->assertUnprocessable();
    })->with([
        'unknown' => [['task.created', 'ping']],
        'repeated' => [['task.created', 'task.created']],
    ]);

    it('can\'t be created disabled or with a long description', function () {
        $this->postJson('/api/webhooks', webhookInput(['enabled' => false]))->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->postJson('/api/webhooks', webhookInput(['description' => str_repeat('a', 201)]))->assertUnprocessable()->assertJsonValidationErrors('description');
    });

    it('needs a verified email', function () {
        $this->owner->forceFill(['email_verified_at' => null])->save();

        $this->postJson('/api/webhooks', webhookInput())->assertForbidden()->assertJsonPath('code', 'email_unverified');
    });
});

describe('the URL (SSRF)', function () {
    it('refuses URLs the server must not call', function (string $url, array $dns = []) {
        fakeDns($dns);

        $this->postJson('/api/webhooks', webhookInput(['url' => $url]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        expect(inOrganization($this->acme, fn () => WebhookEndpoint::query()->count()))->toBe(0);
    })->with([
        'http' => ['http://hooks.example.com/'],
        'not a URL' => ['hooks.example.com'],
        'ftp' => ['ftp://hooks.example.com/'],
        'credentials' => ['https://user:pass@hooks.example.com/'],
        'unknown host' => ['https://nowhere.example.com/'],
        'loopback' => ['https://127.0.0.1/'],
        'localhost' => ['https://localhost/', ['localhost' => ['127.0.0.1']]],
        'private 10/8' => ['https://10.1.2.3/'],
        'private 192.168' => ['https://192.168.1.10/'],
        'private 172.16/12' => ['https://172.20.0.1/'],
        'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
        'CGNAT' => ['https://100.64.0.1/'],
        'unspecified' => ['https://0.0.0.0/'],
        'IPv6 loopback' => ['https://[::1]/'],
        'IPv6 unique local' => ['https://[fd00::1]/'],
        'IPv6 link-local' => ['https://[fe80::1]/'],
        'IPv4-mapped IPv6' => ['https://[::ffff:127.0.0.1]/'],
        '6to4' => ['https://[2002:7f00:1::1]/'],
        'a name for a private address' => ['https://internal.example.com/', ['internal.example.com' => ['10.0.0.5']]],
        'public and private addresses' => ['https://mixed.example.com/', ['mixed.example.com' => ['93.184.216.34', '192.168.0.1']]],
        'an international name (curl would resolve its xn-- form itself)' => ['https://ë.example.com/', ['ë.example.com' => ['93.184.216.34']]],
        'a trailing dot' => ['https://hooks.example.com./', ['hooks.example.com.' => ['93.184.216.34']]],
        'an underscore' => ['https://bad_host.example.com/', ['bad_host.example.com' => ['93.184.216.34']]],
    ]);

    it('accepts public addresses', function (string $url, array $dns = []) {
        fakeDns($dns);

        $this->postJson('/api/webhooks', webhookInput(['url' => $url]))->assertCreated();
    })->with([
        'a public name' => ['https://hooks.example.com/x?y=1'],
        'a public IPv4' => ['https://93.184.216.34:8443/hook'],
        'a public IPv6' => ['https://v6.example.com/', ['v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946']]],
        'an international name in its xn-- form' => ['https://xn--ida.example.com/', ['xn--ida.example.com' => ['93.184.216.34']]],
    ]);

    it('lets only the exact private targets configured through, over http too', function () {
        config(['webhooks.allowed_private_targets' => ['127.0.0.1:8113']]);

        $this->postJson('/api/webhooks', webhookInput(['url' => 'http://127.0.0.1:8113/hook']))->assertCreated();
        $this->postJson('/api/webhooks', webhookInput(['url' => 'http://127.0.0.1:8114/hook']))->assertUnprocessable();
        $this->postJson('/api/webhooks', webhookInput(['url' => 'https://169.254.169.254/']))->assertUnprocessable();
    });
});

describe('editing', function () {
    beforeEach(function () {
        $this->endpoint = createWebhook($this->acme, ['url' => 'https://hooks.example.com/old']);
    });

    it('changes only the fields sent', function () {
        $this->patchJson("/api/webhooks/{$this->endpoint->id}", ['events' => ['task.updated']])
            ->assertOk()
            ->assertJsonPath('data.events', ['task.updated'])
            ->assertJsonPath('data.url', 'https://hooks.example.com/old');
    });

    it('checks a new URL', function () {
        $this->patchJson("/api/webhooks/{$this->endpoint->id}", ['url' => 'https://10.0.0.1/'])->assertUnprocessable()->assertJsonValidationErrors('url');
    });

    it('turns a webhook off and on, starting its failure count over', function () {
        $this->endpoint->forceFill(['consecutive_failures' => 9])->saveQuietly();

        $this->patchJson("/api/webhooks/{$this->endpoint->id}", ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.disabled_reason', 'manual');
        $this->patchJson("/api/webhooks/{$this->endpoint->id}", ['enabled' => true])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.disabled_reason', null);

        expect($this->endpoint->fresh()->consecutive_failures)->toBe(0)
            ->and(auditEntries($this->acme, AuditAction::WebhookUpdated)->pluck('changes')->all())->toEqual([
                ['enabled' => ['old' => true, 'new' => false]],
                ['enabled' => ['old' => false, 'new' => true]],
            ]);
    });

    it('audits a URL change by host, without the path', function () {
        $this->patchJson("/api/webhooks/{$this->endpoint->id}", ['url' => 'https://hooks.example.com/new-secret-path'])->assertOk();
        fakeDns(['other.example.com' => ['93.184.216.35']]);
        $this->patchJson("/api/webhooks/{$this->endpoint->id}", ['url' => 'https://other.example.com/x'])->assertOk();

        $changes = auditEntries($this->acme, AuditAction::WebhookUpdated)->sortBy('id')->pluck('changes')->all();

        expect($changes)->toEqual([
            ['url' => ['old' => 'hooks.example.com', 'new' => 'hooks.example.com (new path)']],
            ['url' => ['old' => 'hooks.example.com', 'new' => 'other.example.com']],
        ])->and(json_encode($changes))->not->toContain('secret-path');
    });

    it('rolls the secret: a new one, shown once, audited without it', function () {
        $secret = $this->postJson("/api/webhooks/{$this->endpoint->id}/secret")->assertOk()->json('meta.secret');

        expect($secret)->not->toBe('whsec_test_secret')
            ->and($this->endpoint->fresh()->secret)->toBe($secret)
            ->and(auditEntries($this->acme, AuditAction::WebhookSecretRolled)->sole()->toJson())->not->toContain($secret)
            ->and(auditEntries($this->acme, AuditAction::WebhookUpdated))->toHaveCount(0);
    });

    it('deletes a webhook with its deliveries', function () {
        createDelivery($this->endpoint);

        $this->deleteJson("/api/webhooks/{$this->endpoint->id}")->assertNoContent();

        expect(DB::table('webhook_endpoints')->count())->toBe(0)
            ->and(DB::table('webhook_deliveries')->count())->toBe(0)
            ->and(auditEntries($this->acme, AuditAction::WebhookDeleted)->sole()->subject_label)->toBe('hooks.example.com');
    });
});

describe('permissions', function () {
    it('lets only roles with webhooks.manage in, before validation', function (string $method, string $uri) {
        $endpoint = createWebhook($this->acme);
        $delivery = createDelivery($endpoint);
        $uri = str_replace(['{webhook}', '{delivery}'], [$endpoint->id, $delivery->id], $uri);

        foreach ([DefaultRole::Admin, DefaultRole::Member] as $role) {
            Sanctum::actingAs(addMember($this->acme, $role));
            $this->json($method, $uri, ['url' => 'not a url'])->assertForbidden();
        }

        $custom = createRole($this->acme, 'Integrations', [Permission::WebhooksManage]);
        Sanctum::actingAs(addMember($this->acme, $custom));
        expect($this->json($method, $uri, webhookInput())->status())->toBeIn([200, 201, 202, 204]);
    })->with([
        ['GET', '/api/webhooks'],
        ['POST', '/api/webhooks'],
        ['GET', '/api/webhooks/{webhook}'],
        ['PATCH', '/api/webhooks/{webhook}'],
        ['DELETE', '/api/webhooks/{webhook}'],
        ['POST', '/api/webhooks/{webhook}/secret'],
        ['POST', '/api/webhooks/{webhook}/test'],
        ['GET', '/api/webhooks/{webhook}/deliveries'],
        ['POST', '/api/webhooks/{webhook}/deliveries/{delivery}/resend'],
    ]);

    it('needs a current organization', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/webhooks')->assertForbidden();
    });
});

it('refuses private webhook targets outside local and testing', function (string $env) {
    config(['billing.provider' => 'stripe', 'webhooks.allowed_private_targets' => ['127.0.0.1:8113']]);
    $this->app['env'] = $env;

    try {
        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(LogicException::class, 'WEBHOOKS_ALLOWED_PRIVATE_TARGETS is only allowed when APP_ENV is local or testing.');

        config(['webhooks.allowed_private_targets' => []]);
        (new AppServiceProvider($this->app))->boot();
    } finally {
        $this->app['env'] = 'testing';
    }
})->with(['production', 'staging']);

it('answers the same for a host that can\'t be found as for a private one', function () {
    fakeDns(['internal.example.com' => ['10.0.0.5']]);

    $unknown = $this->postJson('/api/webhooks', webhookInput(['url' => 'https://nowhere.example.com/']))->json('errors.url');
    $private = $this->postJson('/api/webhooks', webhookInput(['url' => 'https://internal.example.com/']))->json('errors.url');

    expect($unknown)->toBe($private);
});

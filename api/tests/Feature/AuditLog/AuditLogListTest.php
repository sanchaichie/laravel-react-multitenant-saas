<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// Reading the log: who may, filters, pages and retention.

beforeEach(function () {
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner, 'Acme');
    $this->owner->update(['name' => 'Maya']);
});

/** An entry written straight to the table, in the organization. */
function auditEntry(array $attributes = []): AuditLog
{
    return inOrganization(test()->organization, function () use ($attributes) {
        $entry = (new AuditLog)->forceFill([
            'action' => AuditAction::TaskCreated,
            'subject_type' => 'task',
            'subject_id' => 1,
            'subject_label' => 'A task',
            'ip_address' => '203.0.113.7',
            ...$attributes,
        ]);
        $entry->save();

        return $entry;
    });
}

it('lets those with audit_log.view read the log', function (DefaultRole $role, int $status) {
    Sanctum::actingAs($role === DefaultRole::Owner ? $this->owner : addMember($this->organization, $role));

    $this->getJson('/api/audit-logs')->assertStatus($status);
    $this->getJson('/api/audit-log/actions')->assertStatus($status);
})->with([
    'owner' => [DefaultRole::Owner, 200],
    'admin' => [DefaultRole::Admin, 200],
    'member' => [DefaultRole::Member, 403],
]);

it('follows a custom role\'s audit_log.view', function (array $permissions, int $status) {
    Sanctum::actingAs(addMember($this->organization, createRole($this->organization, 'Custom', $permissions)));

    $this->getJson('/api/audit-logs')->assertStatus($status);
})->with([
    'with it' => [[Permission::AuditLogView], 200],
    'without it' => [[Permission::MembersManage, Permission::RolesManage], 403],
]);

it('lists entries newest first, with the actor, and IP addresses only for owners', function () {
    $older = auditEntry(['actor_id' => $this->owner->id, 'changes' => ['title' => ['old' => 'a', 'new' => 'b']]]);
    $newer = auditEntry(['action' => AuditAction::MemberInvited, 'subject_type' => 'invitation', 'metadata' => ['email' => 'ana@example.com']]);

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/audit-logs')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.action_label', 'Invited someone')
        ->assertJsonPath('data.0.actor', null)
        ->assertJsonPath('data.0.metadata.email', 'ana@example.com')
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.1.actor', ['id' => $this->owner->id, 'name' => 'Maya'])
        ->assertJsonPath('data.1.subject', ['type' => 'task', 'id' => 1, 'label' => 'A task'])
        ->assertJsonPath('data.1.changes.title.new', 'b')
        ->assertJsonPath('data.0.ip_address', '203.0.113.7')
        ->assertJsonStructure(['meta' => ['next_cursor', 'per_page']]);

    Sanctum::actingAs(addMember($this->organization, DefaultRole::Admin));
    $this->getJson('/api/audit-logs')->assertOk()->assertJsonMissingPath('data.0.ip_address');
});

it('pages with a cursor, without overlap', function () {
    $ids = collect(range(1, AuditLog::PER_PAGE + 3))->map(fn () => auditEntry()->id)->reverse()->values();
    Sanctum::actingAs($this->owner);

    $first = $this->getJson('/api/audit-logs')->assertOk();
    $second = $this->getJson('/api/audit-logs?cursor='.$first->json('meta.next_cursor'))->assertOk();

    expect(collect($first->json('data'))->pluck('id')->all())->toBe($ids->take(AuditLog::PER_PAGE)->all())
        ->and(collect($second->json('data'))->pluck('id')->all())->toBe($ids->skip(AuditLog::PER_PAGE)->values()->all())
        ->and($second->json('meta.next_cursor'))->toBeNull();
});

it('filters by action, actor and days', function () {
    $ana = addMember($this->organization);
    $invite = auditEntry(['action' => AuditAction::MemberInvited]);
    $byAna = auditEntry(['actor_id' => $ana->id]);
    $old = auditEntry(['created_at' => now()->subDays(10)]);
    $today = auditEntry(['created_at' => now()->startOfDay()]);
    Sanctum::actingAs($this->owner);

    $ids = fn (string $query) => collect($this->getJson("/api/audit-logs?{$query}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('action=member.invited'))->toBe([$invite->id])
        ->and($ids("actor={$ana->id}"))->toBe([$byAna->id])
        ->and($ids('to='.now()->subDays(9)->toDateString()))->toBe([$old->id])
        ->and($ids('from='.now()->toDateString()))->toBe([$today->id, $byAna->id, $invite->id])
        ->and($ids('from='.now()->subDays(10)->toDateString().'&to='.now()->subDays(10)->toDateString()))->toBe([$old->id]);
});

it('validates the filters', function (string $query, string $field) {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/audit-logs?{$query}")->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'unknown action' => ['action=task.archived', 'action'],
    'actor not an id' => ['actor=me', 'actor'],
    'bad date' => ['from=30/10/2026', 'from'],
    'end before start' => ['from=2026-10-30&to=2026-10-29', 'to'],
    'cursor not ours' => ['cursor=nonsense', 'cursor'],
    'cursor without an id' => ['cursor='.rawurlencode(base64_encode(json_encode(['name' => 'x', '_pointsToNextItems' => true]))), 'cursor'],
]);

it('shows email addresses only to roles that may see invitations', function () {
    auditEntry(['action' => AuditAction::MemberInvited, 'subject_type' => 'invitation', 'subject_label' => 'ana@example.com', 'metadata' => ['email' => 'ana@example.com', 'role' => ['id' => 1, 'name' => 'Member']]]);

    Sanctum::actingAs(addMember($this->organization, DefaultRole::Admin));
    $this->getJson('/api/audit-logs')->assertOk()
        ->assertJsonPath('data.0.subject.label', 'ana@example.com')
        ->assertJsonPath('data.0.metadata.email', 'ana@example.com');

    Sanctum::actingAs(addMember($this->organization, createRole($this->organization, 'Auditor', [Permission::AuditLogView])));
    $this->getJson('/api/audit-logs?action=member.invited')->assertOk()
        ->assertJsonPath('data.0.subject.label', null)
        ->assertJsonMissingPath('data.0.metadata.email')
        ->assertJsonPath('data.0.metadata.role.name', 'Member');
});

it('lists the action catalogue for the filter', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/audit-log/actions')
        ->assertOk()
        ->assertJsonCount(count(AuditAction::cases()), 'data')
        ->assertJsonPath('data.0', ['value' => 'organization.created', 'label' => 'Created the organization', 'group' => 'Organization']);
});

it('prunes entries past the retention in every organization, or none with 0', function () {
    [, $other] = userInOrganization(name: 'Other');
    $fresh = auditEntry(['created_at' => now()->subDays(364)]);
    $old = auditEntry(['created_at' => now()->subDays(366)]);
    $this->travel(-400)->days();
    inOrganization($other, fn () => Task::factory()->create());
    $this->travelBack();

    config(['audit.retention_days' => 0]);
    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();
    expect(AuditLog::withoutGlobalScopes()->count())->toBe(3);

    config(['audit.retention_days' => 365]);
    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();
    expect(AuditLog::withoutGlobalScopes()->pluck('id')->all())->toBe([$fresh->id]);
});

it('never changes or deletes a single entry', function (string $method) {
    $entry = auditEntry();

    inOrganization($this->organization, fn () => $method === 'update' ? $entry->forceFill(['subject_label' => 'x'])->save() : $entry->delete());
})->with(['update', 'delete'])->throws(LogicException::class);

it('grants audit_log.view to existing roles that manage members, and only them', function () {
    $lead = createRole($this->organization, 'Lead', [Permission::MembersManage]);
    $viewer = createRole($this->organization, 'Viewer', [Permission::TasksCreate]);
    $admin = $this->organization->role(DefaultRole::Admin);
    // As they were before the migration.
    foreach ([$lead, $admin] as $role) {
        DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($role->permissions->reject(Permission::AuditLogView)->map->value->values())]);
    }

    $migration = require database_path('migrations/2026_10_30_120001_grant_audit_log_view.php');
    $migration->up();
    $migration->up(); // twice: no duplicate

    expect($lead->fresh()->permissions->all())->toBe([Permission::MembersManage, Permission::AuditLogView])
        ->and($admin->fresh()->permissions->filter(fn ($p) => $p === Permission::AuditLogView))->toHaveCount(1)
        ->and($viewer->fresh()->permissions->all())->toBe([Permission::TasksCreate])
        ->and($this->organization->role(DefaultRole::Owner)->permissions->all())->toBe([]);

    $migration->down();
    expect($lead->fresh()->permissions->all())->toBe([Permission::MembersManage]);
});

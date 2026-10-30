<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\AuditLog;
use App\Models\Task;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

// Actions that record themselves with RecordAudit.

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->organization] = userInOrganization(DefaultRole::Owner, 'Acme');
    Sanctum::actingAs($this->owner);
});

it('records an invitation without its token, and revoking it', function () {
    $id = $this->postJson('/api/invitations', ['email' => 'ana@example.com', 'role_id' => roleId(DefaultRole::Admin)])->assertCreated()->json('data.id');
    $this->deleteJson("/api/invitations/{$id}")->assertNoContent();

    [$invited, $revoked] = auditEntries($this->organization)->all();

    expect($invited->action)->toBe(AuditAction::MemberInvited)
        ->and($invited->actor_id)->toBe($this->owner->id)
        ->and($invited->subject_type)->toBe('invitation')
        ->and($invited->subject_label)->toBe('ana@example.com')
        ->and($invited->metadata)->toEqual(['email' => 'ana@example.com', 'role' => ['id' => roleId(DefaultRole::Admin), 'name' => 'Admin']])
        ->and($revoked->action)->toBe(AuditAction::InvitationRevoked)
        ->and($revoked->subject_id)->toBe($id);

    // Neither the token nor its hash, anywhere in the row.
    $row = json_encode(AuditLog::withoutGlobalScopes()->get()->toArray());
    expect($row)->not->toContain('token');
});

it('records joining in the invitation\'s organization, not the user\'s current one', function () {
    [, $token] = createInvitation($this->organization, 'ana@example.com', DefaultRole::Admin);
    [$ana, $own] = userInOrganization(DefaultRole::Owner, 'Ana\'s');
    $ana->update(['email' => 'ana@example.com', 'name' => 'Ana']);
    Sanctum::actingAs($ana);

    $this->postJson("/api/join/{$token}")->assertOk();

    $joined = auditEntries($this->organization, AuditAction::MemberJoined)->sole();
    expect($joined->actor_id)->toBe($ana->id)
        ->and($joined->subject_label)->toBe('Ana')
        ->and($joined->metadata['role']['name'])->toEqual('Admin')
        ->and(auditEntries($own))->toHaveCount(0);
});

it('records a role change with the role the member really had', function () {
    $ana = addMember($this->organization, attributes: ['name' => 'Ana']);

    $this->patchJson("/api/members/{$ana->id}", ['role_id' => roleId(DefaultRole::Admin)])->assertOk();
    // Giving the same role again changes nothing and records nothing.
    $this->patchJson("/api/members/{$ana->id}", ['role_id' => roleId(DefaultRole::Admin)])->assertOk();

    $entry = auditEntries($this->organization, AuditAction::MemberRoleChanged)->sole();
    expect($entry->subject_id)->toBe($ana->id)
        ->and($entry->subject_label)->toBe('Ana')
        ->and($entry->changes)->toEqual(['role' => [
            'old' => ['id' => roleId(DefaultRole::Member), 'name' => 'Member'],
            'new' => ['id' => roleId(DefaultRole::Admin), 'name' => 'Admin'],
        ]]);
});

it('records a removal with how many tasks lost their assignee, and a member leaving', function () {
    $ana = addMember($this->organization, attributes: ['name' => 'Ana']);
    $ben = addMember($this->organization, DefaultRole::Admin);
    inOrganization($this->organization, fn () => Task::factory()->count(2)->assignedTo($ana)->create());

    $this->deleteJson("/api/members/{$ana->id}")->assertNoContent();
    Sanctum::actingAs($ben);
    $this->deleteJson("/api/members/{$ben->id}")->assertNoContent();

    [$removed, $left] = auditEntries($this->organization, AuditAction::MemberRemoved)->all();
    expect($removed->actor_id)->toBe($this->owner->id)
        ->and($removed->subject_label)->toBe('Ana')
        ->and($removed->metadata)->toMatchArray(['role' => ['id' => roleId(DefaultRole::Member, $this->organization), 'name' => 'Member'], 'left' => false, 'unassigned_tasks' => 2])
        ->and($left->actor_id)->toBe($ben->id)
        ->and($left->metadata['left'])->toBeTrue()
        // The unassignments are counted, not recorded one by one.
        ->and(auditEntries($this->organization, AuditAction::TaskUpdated))->toHaveCount(0);
});

it('leaves no entry when the action is refused and rolled back', function () {
    $this->patchJson("/api/members/{$this->owner->id}", ['role_id' => roleId(DefaultRole::Admin)])->assertUnprocessable();
    $this->deleteJson("/api/members/{$this->owner->id}")->assertUnprocessable();

    expect(auditEntries($this->organization))->toHaveCount(0);
});

it('records the IP of the request, and none outside one', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson('/api/tasks', ['title' => 'From the web'])->assertCreated();

    auth()->forgetGuards(); // no logged-in user: like a job
    inOrganization($this->organization, fn () => Task::factory()->create());

    [$web, $job] = auditEntries($this->organization)->all();
    expect($web->ip_address)->toBe('203.0.113.7')
        ->and($job->actor_id)->toBeNull()
        ->and($job->ip_address)->toBeNull();
});

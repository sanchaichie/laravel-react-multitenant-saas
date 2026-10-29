<?php

use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

// Org A cannot read or write org B's invitations.

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(OrganizationRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(OrganizationRole::Owner, 'Org B');
    [$this->invitationB] = createInvitation($this->orgB, 'for-b@example.com');
});

it('never lists another organization\'s invitations', function () {
    createInvitation($this->orgA, 'for-a@example.com');
    Sanctum::actingAs($this->ownerA);

    $this->getJson('/api/invitations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'for-a@example.com');
});

it('can\'t revoke another organization\'s invitation', function () {
    Sanctum::actingAs($this->ownerA);

    $this->deleteJson("/api/invitations/{$this->invitationB->id}")->assertNotFound();

    expect(OrganizationInvitation::withoutGlobalScopes()->whereKey($this->invitationB->id)->exists())->toBeTrue();
});

it('creates invitations in the current organization, whatever the request says', function () {
    Sanctum::actingAs($this->ownerA);

    $this->postJson('/api/invitations', ['email' => 'x@example.com', 'role' => 'member', 'organization_id' => $this->orgB->id])
        ->assertCreated();

    expect(OrganizationInvitation::withoutGlobalScopes()->where('email', 'x@example.com')->sole()->organization_id)->toBe($this->orgA->id);
});

it('leaves another organization\'s invitation to the same address alone when inviting', function () {
    Mail::fake();
    [$ownerInviteB, $tokenB] = createInvitation($this->orgB, 'same@example.com', OrganizationRole::Owner);
    // An admin of A: B's owner invitation must neither block them ("a role you can't give")
    // nor be replaced.
    Sanctum::actingAs(addMember($this->orgA, OrganizationRole::Admin));

    $this->postJson('/api/invitations', ['email' => 'same@example.com', 'role' => 'member'])->assertCreated();

    $stillThere = OrganizationInvitation::findByToken($tokenB);
    expect($stillThere?->id)->toBe($ownerInviteB->id)
        ->and($stillThere->organization_id)->toBe($this->orgB->id);
});

it('checks "already a member" in the current organization only', function () {
    Mail::fake();
    $memberB = addMember($this->orgB, attributes: ['email' => 'in-b@example.com']);
    Sanctum::actingAs($this->ownerA);

    $this->postJson('/api/invitations', ['email' => $memberB->email, 'role' => 'member'])->assertCreated();
});

it('stays isolated for someone in both organizations who hasn\'t switched', function () {
    $this->orgB->members()->attach($this->ownerA, ['role' => OrganizationRole::Owner]);
    Sanctum::actingAs($this->ownerA);

    $this->getJson('/api/invitations')->assertJsonMissing(['email' => 'for-b@example.com']);
    $this->deleteJson("/api/invitations/{$this->invitationB->id}")->assertNotFound();
});

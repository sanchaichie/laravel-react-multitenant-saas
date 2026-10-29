<?php

use App\Enums\OrganizationRole;
use Laravel\Sanctum\Sanctum;

// Org A cannot read or write org B's members.

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(OrganizationRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(OrganizationRole::Owner, 'Org B');
    $this->memberB = addMember($this->orgB, OrganizationRole::Member, ['name' => 'Only in B']);
});

it('never lists another organization\'s members', function () {
    Sanctum::actingAs($this->ownerA);

    $ids = collect($this->getJson('/api/members')->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$this->ownerA->id]);
});

it('can\'t change or remove another organization\'s members', function () {
    Sanctum::actingAs($this->ownerA);

    $this->patchJson("/api/members/{$this->memberB->id}", ['role' => 'admin'])->assertNotFound();
    $this->deleteJson("/api/members/{$this->memberB->id}")->assertNotFound();
    $this->deleteJson("/api/members/{$this->ownerB->id}")->assertNotFound();

    $membership = $this->orgB->members()->findOrFail($this->memberB->id)->membership;
    expect($membership->role)->toBe(OrganizationRole::Member)
        ->and($this->ownerB->isMemberOf($this->orgB))->toBeTrue();
});

it('stays isolated for someone in both organizations who hasn\'t switched', function () {
    $this->orgB->members()->attach($this->ownerA, ['role' => OrganizationRole::Owner]);
    Sanctum::actingAs($this->ownerA);

    // Acting in A (current), B's member is out of reach even though A's owner is also B's owner.
    $this->getJson('/api/members')->assertJsonMissing(['id' => $this->memberB->id]);
    $this->patchJson("/api/members/{$this->memberB->id}", ['role' => 'admin'])->assertNotFound();
    $this->deleteJson("/api/members/{$this->memberB->id}")->assertNotFound();

    expect($this->memberB->isMemberOf($this->orgB))->toBeTrue()
        ->and($this->orgB->members()->findOrFail($this->memberB->id)->membership->role)->toBe(OrganizationRole::Member);
});

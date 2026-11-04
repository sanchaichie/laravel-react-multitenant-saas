<?php

use App\Enums\DefaultRole;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->owner, $this->acme] = userInOrganization();
    subscribe($this->acme);
});

it('limits each user to 20 Stripe calls a minute', function () {
    Sanctum::actingAs($this->owner);

    foreach (range(1, 20) as $_) {
        $this->getJson('/api/billing/invoices')->assertOk();
    }

    $this->getJson('/api/billing/invoices')
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'Too many billing requests. Try again in a minute.');

    $this->travel(61)->seconds();

    $this->getJson('/api/billing/invoices')->assertOk();
});

it('limits an organization to 60 Stripe calls a minute, however many owners it has', function () {
    $owners = [$this->owner, addMember($this->acme, DefaultRole::Owner), addMember($this->acme, DefaultRole::Owner)];

    foreach ($owners as $owner) {
        Sanctum::actingAs($owner);
        foreach (range(1, 20) as $_) {
            $this->getJson('/api/billing/invoices')->assertOk();
        }
    }

    // A fourth owner hasn't made a call yet, but the organization has used its 60.
    Sanctum::actingAs(addMember($this->acme, DefaultRole::Owner));
    $this->getJson('/api/billing/invoices')
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'Too many billing requests for this organization. Try again in a minute.');
    $this->postJson('/api/billing/portal')->assertTooManyRequests();
});

it('does not count members without billing.manage toward the organization\'s limit', function () {
    foreach (range(1, 4) as $_) {
        Sanctum::actingAs(addMember($this->acme, DefaultRole::Member));
        foreach (range(1, 20) as $_) {
            $this->getJson('/api/billing/invoices')->assertForbidden();
        }
    }

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/billing/invoices')->assertOk();
});

it('counts checkout, portal and invoices toward the same limit', function () {
    Sanctum::actingAs($this->owner);

    foreach (range(1, 10) as $_) {
        $this->postJson('/api/billing/portal')->assertOk();
    }
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertUnprocessable();
    }

    $this->getJson('/api/billing/invoices')->assertTooManyRequests();
    $this->postJson('/api/billing/credits/checkout', ['pack' => 'credits_10'])->assertTooManyRequests();
});

it('does not limit the billing overview, which makes no Stripe call', function () {
    Sanctum::actingAs($this->owner);

    foreach (range(1, 25) as $_) {
        $this->getJson('/api/billing')->assertOk();
    }
});

it('lets a member of two organizations use the other one when the first is at its limit', function () {
    [$ownerB, $orgB] = userInOrganization(DefaultRole::Owner, 'Org B');
    subscribe($orgB);
    $orgB->members()->attach($this->owner, ['role_id' => $orgB->role(DefaultRole::Owner)->id]);

    // Three other owners use up Acme's 60; the shared owner hasn't made a call.
    foreach (range(1, 3) as $_) {
        Sanctum::actingAs(addMember($this->acme, DefaultRole::Owner));
        foreach (range(1, 20) as $_) {
            $this->getJson('/api/billing/invoices')->assertOk();
        }
    }

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/billing/invoices')->assertTooManyRequests();

    $this->postJson("/api/organizations/{$orgB->id}/switch")->assertOk();
    $this->getJson('/api/billing/invoices')->assertOk();

    // Org B's own owner isn't affected by Acme's limit either.
    Sanctum::actingAs($ownerB);
    $this->getJson('/api/billing/invoices')->assertOk();
});

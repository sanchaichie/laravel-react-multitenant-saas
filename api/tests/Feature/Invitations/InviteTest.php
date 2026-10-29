<?php

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Mail::fake();
    [$this->owner, $this->organization] = userInOrganization(OrganizationRole::Owner, 'Acme');
    $this->owner->update(['name' => 'Maya']);
    Sanctum::actingAs($this->owner);
});

it('invites by email, stores only a hash of the token and queues the email with a working link', function () {
    $this->postJson('/api/invitations', ['email' => ' Ana@Example.com ', 'role' => 'admin'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ana@example.com')
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.invited_by', 'Maya')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_hash');

    $invitation = OrganizationInvitation::withoutGlobalScopes()->sole();
    expect($invitation->organization_id)->toBe($this->organization->id)
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();

    Mail::assertQueued(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use ($invitation) {
        $token = str($mail->url)->afterLast('/join/')->toString();

        return $mail->hasTo('ana@example.com')
            && str_starts_with($mail->url, config('app.frontend_url').'/join/')
            && strlen($token) === 48
            && $invitation->token_hash === OrganizationInvitation::hashToken($token)
            && $mail->organizationName === 'Acme'
            && $mail->inviterName === 'Maya'
            && $mail->roleName === 'Admin';
    });
});

it('replaces a pending invitation to the same address', function () {
    [$old, $oldToken] = createInvitation($this->organization, 'ana@example.com');

    $this->postJson('/api/invitations', ['email' => 'ana@example.com', 'role' => 'admin'])->assertCreated();

    $pending = OrganizationInvitation::withoutGlobalScopes()->sole();
    expect($pending->id)->not->toBe($old->id)
        ->and($pending->role)->toBe(OrganizationRole::Admin)
        ->and(OrganizationInvitation::findByToken($oldToken))->toBeNull();
});

it('won\'t replace an invitation for a role the inviter can\'t give', function () {
    createInvitation($this->organization, 'ana@example.com', OrganizationRole::Owner);
    Sanctum::actingAs(addMember($this->organization, OrganizationRole::Admin));

    $this->postJson('/api/invitations', ['email' => 'ana@example.com', 'role' => 'member'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect(OrganizationInvitation::withoutGlobalScopes()->sole()->role)->toBe(OrganizationRole::Owner);
});

it('refuses to invite someone who is already a member', function () {
    addMember($this->organization, attributes: ['email' => 'ana@example.com']);

    $this->postJson('/api/invitations', ['email' => 'ANA@example.com', 'role' => 'member'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'This person is already a member of the organization.');

    Mail::assertNothingQueued();
});

it('validates email and role', function (array $body, string $field) {
    $this->postJson('/api/invitations', $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'no email' => [['role' => 'member'], 'email'],
    'bad email' => [['email' => 'nope', 'role' => 'member'], 'email'],
    'no role' => [['email' => 'ana@example.com'], 'role'],
    'unknown role' => [['email' => 'ana@example.com', 'role' => 'boss'], 'role'],
]);

it('needs a verified email to invite', function () {
    Sanctum::actingAs(addMember($this->organization, OrganizationRole::Admin, ['email_verified_at' => null]));

    $this->postJson('/api/invitations', ['email' => 'ana@example.com', 'role' => 'member'])
        ->assertForbidden()
        ->assertJsonPath('code', 'email_unverified');

    Mail::assertNothingQueued();
});

it('limits invitations to 20 a minute per user', function () {
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/invitations', ['email' => "person{$i}@example.com", 'role' => 'member'])->assertCreated();
    }

    $this->postJson('/api/invitations', ['email' => 'one-more@example.com', 'role' => 'member'])->assertTooManyRequests();
});

it('lists pending invitations, newest first, without accepted ones', function () {
    [$older] = createInvitation($this->organization, 'older@example.com', attributes: ['created_at' => now()->subDay()]);
    [$newer] = createInvitation($this->organization, 'newer@example.com');
    createInvitation($this->organization, 'done@example.com', attributes: ['accepted_at' => now()]);

    $this->getJson('/api/invitations')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.0.can.delete', true);
});

it('revokes an invitation, after which its link no longer works', function () {
    [$invitation, $token] = createInvitation($this->organization, 'ana@example.com');

    $this->deleteJson("/api/invitations/{$invitation->id}")->assertNoContent();

    Sanctum::actingAs(User::factory()->create(['email' => 'ana@example.com']));
    $this->getJson("/api/join/{$token}")->assertNotFound();
});

it('tells the form which roles the requesting user may invite with', function (OrganizationRole $role, array $invitable) {
    Sanctum::actingAs($role === OrganizationRole::Owner ? $this->owner : addMember($this->organization, $role));

    $this->getJson('/api/invitations')->assertOk()->assertJsonPath('meta.invitable_roles', $invitable);
})->with([
    'owner' => [OrganizationRole::Owner, ['owner', 'admin', 'member']],
    'admin' => [OrganizationRole::Admin, ['admin', 'member']],
]);

it('renders names in the email as text, never as Markdown links', function () {
    $html = (new OrganizationInvitationMail(
        organizationName: 'Security alert: [confirm your account](https://evil.example/login)',
        inviterName: '**Support**',
        roleName: 'Member',
        url: config('app.frontend_url').'/join/'.str_repeat('a', 48),
        expiresAt: 'Fri, Nov 6, 2026 12:00 PM UTC',
    ))->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('<strong>Support</strong>')
        ->and($html)->toContain('href="'.config('app.frontend_url').'/join/');
});

it('prunes invitations accepted or expired over 30 days ago, in every organization', function () {
    [$fresh] = createInvitation($this->organization, 'fresh@example.com');
    [$recentlyExpired] = createInvitation($this->organization, 'recent@example.com', attributes: ['expires_at' => now()->subDays(10)]);
    [$oldExpired] = createInvitation($this->organization, 'old@example.com', attributes: ['expires_at' => now()->subDays(31)]);
    [, $other] = userInOrganization(name: 'Other');
    [$oldAccepted] = createInvitation($other, 'done@example.com', attributes: ['accepted_at' => now()->subDays(31)]);

    $this->artisan('model:prune', ['--model' => [OrganizationInvitation::class]])->assertSuccessful();

    $left = OrganizationInvitation::withoutGlobalScopes()->pluck('id')->all();
    expect($left)->toContain($fresh->id, $recentlyExpired->id)
        ->not->toContain($oldExpired->id)
        ->not->toContain($oldAccepted->id);
});

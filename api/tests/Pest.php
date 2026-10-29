<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * A user who is a member of a new organization (with the given role) and has it as current.
 *
 * @return array{0: User, 1: Organization}
 */
function userInOrganization(OrganizationRole $role = OrganizationRole::Owner, string $name = 'Acme'): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->withMember($user, $role)->create(['name' => $name]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return [$user, $organization];
}

/**
 * Add a new user to the organization with the given role (it becomes their current one).
 *
 * @param  array<string, mixed>  $attributes
 */
function addMember(Organization $organization, OrganizationRole $role = OrganizationRole::Member, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $organization->members()->attach($user, ['role' => $role]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return $user;
}

/**
 * An invitation written directly (no email). Returns it and its plain token.
 *
 * @param  array<string, mixed>  $attributes  any column, e.g. expires_at or accepted_at
 * @return array{0: OrganizationInvitation, 1: string}
 */
function createInvitation(Organization $organization, string $email, OrganizationRole $role = OrganizationRole::Member, array $attributes = []): array
{
    $token = Str::random(48);

    $invitation = inOrganization($organization, function () use ($email, $role, $token, $attributes) {
        $invitation = (new OrganizationInvitation)->forceFill(array_merge([
            'email' => $email,
            'role' => $role,
            'token_hash' => OrganizationInvitation::hashToken($token),
            'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
        ], $attributes));
        $invitation->save();

        return $invitation;
    });

    return [$invitation, $token];
}

/**
 * Run code acting in an organization (no logged-in user needed), e.g. to create
 * tenant-owned rows. Returns what the callback returns.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inOrganization(Organization $organization, Closure $callback): mixed
{
    return app(CurrentOrganization::class)->run($organization, $callback);
}

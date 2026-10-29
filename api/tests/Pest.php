<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

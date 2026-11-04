<?php

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function registration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ], $overrides);
}

it('registers a user and returns a working token', function () {
    $response = $this->postJson('/api/register', registration());

    $response->assertCreated()
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'created_at'], 'token'])
        ->assertJsonPath('user.email', 'ada@example.com')
        ->assertJsonMissingPath('user.password');

    $user = User::sole();
    expect($user->name)->toBe('Ada Lovelace')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and($user->tokens()->sole()->name)->toBe('Unknown device');

    $this->withToken($response->json('token'))
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('creates a personal organization the new user owns and works in', function () {
    $response = $this->postJson('/api/register', registration())->assertCreated();

    $user = User::sole();
    $organization = Organization::sole();

    expect($organization->name)->toBe('Ada Lovelace\'s organization')
        ->and($user->current_organization_id)->toBe($organization->id)
        ->and($organization->members()->sole()->membership->role->name)->toBe(DefaultRole::Owner->label());

    $response->assertJsonPath('user.current_organization.id', $organization->id)
        ->assertJsonPath('user.current_organization.role.name', 'Owner');
});

it('shortens the personal organization\'s name to fit, for the longest allowed name', function () {
    $name = str_repeat('é', 255);

    $this->postJson('/api/register', registration(['name' => $name]))->assertCreated();

    $organization = Organization::sole();
    expect(mb_strlen($organization->name))->toBe(255)
        ->and($organization->name)->toEndWith('\'s organization')
        ->and(User::sole()->name)->toBe($name);
});

it('creates no organization when registration fails', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/register', registration())->assertUnprocessable();

    expect(Organization::count())->toBe(0);
});

it('names the token after the device', function () {
    $this->postJson('/api/register', registration(['device_name' => 'Ada\'s laptop']))->assertCreated();

    expect(User::sole()->tokens()->sole()->name)->toBe('Ada\'s laptop');
});

it('requires name, email and password', function () {
    $this->postJson('/api/register', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);

    expect(User::count())->toBe(0);
});

it('rejects an invalid email', function () {
    $this->postJson('/api/register', registration(['email' => 'not-an-email']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/register', registration())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect(User::count())->toBe(1);
});

it('rejects an email with capitals', function () {
    $this->postJson('/api/register', registration(['email' => 'Ada@Example.com']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('requires the password confirmation to match', function () {
    $this->postJson('/api/register', registration(['password_confirmation' => 'something-else']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('rejects a short password', function () {
    $this->postJson('/api/register', registration(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('throttles repeated registrations', function () {
    foreach (range(1, 6) as $i) {
        $this->postJson('/api/register', registration(['email' => "ada{$i}@example.com"]));
    }

    $this->postJson('/api/register', registration(['email' => 'ada7@example.com']))
        ->assertTooManyRequests();
});

<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    // The flag is static: put back what this test database allows.
    config(['database.allow_destructive_commands' => false]);
    DB::prohibitDestructiveCommands(false);
});

/** Pretend the default connection points at $database, and apply the guard. */
function guardFor(string $database): void
{
    config(['database.connections.'.config('database.default').'.database' => $database]);
    AppServiceProvider::guardDestructiveDatabaseCommands();
}

it('refuses destructive commands on a database that isn\'t a test one', function (string $command) {
    guardFor('react_saas');

    $this->artisan($command, ['--force' => true])->assertFailed();

    expect(Schema::hasTable('users'))->toBeTrue();
})->with(['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe']);

it('allows them on test databases, including parallel ones', function (string $database) {
    guardFor($database);

    $this->artisan('migrate:rollback', ['--pretend' => true, '--force' => true])->assertSuccessful();
})->with(['react_saas_test', 'react_saas_test_test_3']);

it('doesn\'t treat a name merely containing "test" as a test database', function (string $database) {
    guardFor($database);

    $this->artisan('db:wipe', ['--force' => true])->assertFailed();
    expect(Schema::hasTable('users'))->toBeTrue();
})->with(['test_react_saas', 'react_saas_testing', 'latest']);

it('goes by the database in DB_URL, which the connection uses over DB_DATABASE', function () {
    config(['database.connections.'.config('database.default').'.url' => 'pgsql://postgres@127.0.0.1:5432/react_saas']);
    guardFor('react_saas_test');

    $this->artisan('db:wipe', ['--force' => true])->assertFailed();
    expect(Schema::hasTable('users'))->toBeTrue();
});

it('allows a deliberate run with DB_ALLOW_DESTRUCTIVE_COMMANDS', function () {
    config(['database.allow_destructive_commands' => true]);
    guardFor('react_saas');

    $this->artisan('migrate:rollback', ['--pretend' => true, '--force' => true])->assertSuccessful();
});

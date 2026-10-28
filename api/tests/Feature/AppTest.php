<?php

use App\Models\User;

it('answers JSON at the root, with no page', function () {
    config(['app.name' => 'React SaaS']);

    $this->get('/')->assertOk()->assertExactJson(['name' => 'React SaaS API']);
});

it('has a health check (servers.sh waits on it)', function () {
    $this->get('/up')->assertOk();
});

it('stores emails in lowercase whatever the write path', function () {
    $user = User::factory()->create(['email' => 'Grace@Example.com']);

    expect($user->fresh()->email)->toBe('grace@example.com');
});

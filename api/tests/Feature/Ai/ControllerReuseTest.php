<?php

use Laravel\Sanctum\Sanctum;

// The router reuses controller instances across requests in one process (tests,
// Octane). A controller that kept CurrentOrganization from its constructor would
// answer the second request with the first one's organization.

it('answers each request with its own organization', function (string $uri, string $namesAt) {
    [$ada, $acme] = userInOrganization(name: 'Acme');
    [$bob, $beta] = userInOrganization(name: 'Beta');
    recordAiUsage($acme, 0.5, ['user_id' => $ada->id]);
    recordAiUsage($beta, 2.0, ['user_id' => $bob->id]);

    $names = fn ($response) => array_column($response->json($namesAt), 'name');

    Sanctum::actingAs($ada);
    expect($names($this->getJson($uri)->assertOk()))->toBe([$ada->name]);

    Sanctum::actingAs($bob);
    expect($names($this->getJson($uri)->assertOk()))->toBe([$bob->name]);
})->with([
    'members' => ['/api/members', 'data'],
    'ai usage' => ['/api/ai-usage', 'data.by_user.*.user'],
]);

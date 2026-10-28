<?php

it('allows the frontend origin', function () {
    $this->withHeaders([
        'Origin' => 'http://localhost:5173',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'authorization',
    ])
        ->options('/api/me')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');
});

it('does not allow other origins', function () {
    // With a single allowed origin the header is always that origin, so browsers block any other.
    $response = $this->withHeaders([
        'Origin' => 'http://evil.example',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/me');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('http://evil.example')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');
});

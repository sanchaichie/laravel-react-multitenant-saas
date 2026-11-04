<?php

use App\Support\Totp;

// RFC 6238 appendix B (SHA1): the secret is the ASCII "12345678901234567890". The
// RFC prints 8 digits; authenticator apps use the last 6.
it('matches the RFC 6238 test vectors', function (int $time, string $code) {
    $secret = Totp::base32Encode('12345678901234567890');

    expect(Totp::codeAt($secret, Totp::step($time)))->toBe($code);
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
    [20000000000, '353130'],
]);

it('accepts a code one step either side of now, but not two', function () {
    $secret = Totp::generateSecret();
    $now = 1_700_000_000;
    $step = Totp::step($now);

    expect(Totp::verify($secret, Totp::codeAt($secret, $step), time: $now))->toBe($step)
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step - 1), time: $now))->toBe($step - 1)
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step + 1), time: $now))->toBe($step + 1)
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step - 2), time: $now))->toBeNull()
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step + 2), time: $now))->toBeNull();
});

it('refuses a code from a step that was already used', function () {
    $secret = Totp::generateSecret();
    $now = 1_700_000_000;
    $step = Totp::step($now);

    expect(Totp::verify($secret, Totp::codeAt($secret, $step), lastUsedStep: $step, time: $now))->toBeNull()
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step - 1), lastUsedStep: $step - 1, time: $now))->toBeNull()
        ->and(Totp::verify($secret, Totp::codeAt($secret, $step + 1), lastUsedStep: $step, time: $now))->toBe($step + 1);
});

it('refuses anything that is not six digits', function (string $code) {
    expect(Totp::verify(Totp::generateSecret(), $code))->toBeNull();
})->with(['', '12345', '1234567', 'abcdef', '12 345']);

it('round trips base32 and makes 32-character secrets', function () {
    $bytes = random_bytes(20);

    expect(Totp::base32Decode(Totp::base32Encode($bytes)))->toBe($bytes)
        ->and(Totp::generateSecret())->toMatch('/^[A-Z2-7]{32}$/');
});

it('builds the otpauth link for the QR code', function () {
    expect(Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'ada@example.com', 'SaaS Starter'))
        ->toBe('otpauth://totp/SaaS%20Starter:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=SaaS%20Starter&algorithm=SHA1&digits=6&period=30');
});

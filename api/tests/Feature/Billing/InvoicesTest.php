<?php

use App\Billing\Exceptions\BillingUnavailable;
use App\Billing\Invoice;
use App\Billing\StripeBillingGateway;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\AiCreditPurchase;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

afterEach(fn () => ApiRequestor::setHttpClient(null));

beforeEach(function () {
    config(['app.frontend_url' => 'http://app.test']);
    [$this->owner, $this->acme] = userInOrganization();
    Sanctum::actingAs($this->owner);
});

/**
 * Answer stripe-php's calls with $handler($method, $url, $params) => the JSON body,
 * or [status, body] for an error.
 */
function fakeStripeHttp(Closure $handler): void
{
    config(['cashier.secret' => 'sk_test_fake']);
    ApiRequestor::setHttpClient(new class($handler) implements ClientInterface
    {
        public function __construct(private readonly Closure $handler) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $response = ($this->handler)($method, $absUrl, $params);

            // [status, body] for an error, or just the body.
            return is_int($response[0] ?? null)
                ? [json_encode($response[1]), $response[0], []]
                : [json_encode($response), 200, []];
        }
    });
}

function stripeInvoice(string $id, array $attributes = []): array
{
    return array_merge([
        'id' => $id,
        'object' => 'invoice',
        'number' => 'ACME-0001',
        'status' => 'paid',
        'total' => 1900,
        'currency' => 'usd',
        'created' => CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC')->timestamp,
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/test_1',
        'invoice_pdf' => 'https://pay.stripe.com/invoice/acct_1/test_1/pdf',
        'lines' => ['object' => 'list', 'data' => [['id' => 'il_1', 'object' => 'line_item', 'description' => '1 × Pro (at $19.00 / month)']], 'has_more' => false],
    ], $attributes);
}

it('lists the organization\'s invoices', function () {
    subscribe($this->acme);
    $this->billing->setInvoices($this->acme, [
        invoice('in_2', ['number' => 'ACME-0002', 'status' => 'open', 'total' => 1000, 'description' => '$10 of AI credits', 'pdfUrl' => null]),
        invoice('in_1'),
    ]);

    $this->getJson('/api/billing/invoices')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => 'in_2', 'number' => 'ACME-0002', 'status' => 'open', 'total' => 1000, 'currency' => 'usd',
                    'description' => '$10 of AI credits', 'created_at' => '2026-10-01T12:00:00Z',
                    'hosted_url' => 'https://invoice.stripe.com/i/acct_1/test_1', 'pdf_url' => null,
                ],
                [
                    'id' => 'in_1', 'number' => 'ACME-0001', 'status' => 'paid', 'total' => 1900, 'currency' => 'usd',
                    'description' => 'Pro', 'created_at' => '2026-10-01T12:00:00Z',
                    'hosted_url' => 'https://invoice.stripe.com/i/acct_1/test_1', 'pdf_url' => 'https://pay.stripe.com/invoice/acct_1/test_1/pdf',
                ],
            ],
            'meta' => ['next_cursor' => null],
        ]);
});

it('pages through the invoices with a cursor', function () {
    subscribe($this->acme);
    $this->billing->setInvoices($this->acme, array_map(fn (int $n) => invoice("in_{$n}"), range(25, 1)));

    $first = $this->getJson('/api/billing/invoices')->assertOk()->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.id', 'in_25')
        ->assertJsonPath('meta.next_cursor', 'in_6');

    $this->getJson('/api/billing/invoices?starting_after='.$first->json('meta.next_cursor'))->assertOk()
        ->assertJsonPath('data.*.id', ['in_5', 'in_4', 'in_3', 'in_2', 'in_1'])
        ->assertJsonPath('meta.next_cursor', null);

    expect($this->billing->invoiceRequests())->toBe([
        ['organization_id' => $this->acme->id, 'limit' => 20, 'starting_after' => null],
        ['organization_id' => $this->acme->id, 'limit' => 20, 'starting_after' => 'in_6'],
    ]);
});

it('returns no invoices without asking Stripe for an organization that never paid', function () {
    $this->getJson('/api/billing/invoices')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null]]);

    $this->billing->assertNothingStarted();
});

it('validates the cursor', function (mixed $cursor) {
    subscribe($this->acme);

    $this->getJson('/api/billing/invoices?'.http_build_query(['starting_after' => $cursor]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starting_after');

    $this->billing->assertNothingStarted();
})->with([
    'not an invoice id' => ['abc'],
    'a customer id' => ['cus_123'],
    'a path' => ['in_../x'],
    'an array' => [['in_1']],
    'too long' => ['in_'.str_repeat('a', 300)],
]);

it('shows demo invoices from the fake provider for subscriptions and credit purchases', function () {
    $subscription = subscribe($this->acme, ['created_at' => '2026-09-01 10:00:00']);
    $purchase = inOrganization($this->acme, fn () => AiCreditPurchase::query()->forceCreate([
        'pack' => 'credits_10',
        'amount_usd' => 10,
        'stripe_checkout_session_id' => 'cs_test_1',
        'created_at' => '2026-10-05 10:00:00',
    ]));

    $this->getJson('/api/billing/invoices')
        ->assertOk()
        ->assertJsonPath('data.*.id', ["in_fake_credits_{$purchase->id}", "in_fake_sub_{$subscription->id}"])
        ->assertJsonPath('data.0.total', 1000)
        ->assertJsonPath('data.1.total', 1900)
        ->assertJsonPath('data.1.description', 'Pro')
        ->assertJsonPath('data.0.hosted_url', 'http://app.test/billing/invoices');
});

it('reads invoices from Stripe for the organization\'s customer, without drafts', function () {
    subscribe($this->acme);
    $requests = [];
    fakeStripeHttp(function (string $method, string $url, array $params) use (&$requests) {
        $requests[] = [$method, $url, $params];

        return [
            'object' => 'list',
            'url' => '/v1/invoices',
            'has_more' => true,
            'data' => [
                stripeInvoice('in_3', ['status' => 'draft', 'number' => null, 'hosted_invoice_url' => null, 'invoice_pdf' => null]),
                stripeInvoice('in_2', ['status' => 'open', 'total' => 1000]),
                stripeInvoice('in_1'),
            ],
        ];
    });

    $page = app(StripeBillingGateway::class)->invoices($this->acme, 20, 'in_9');

    expect($requests)->toHaveCount(1)
        ->and($requests[0][0])->toBe('get')
        ->and($requests[0][1])->toContain('/v1/invoices')
        ->and($requests[0][2])->toBe(['customer' => $this->acme->stripe_id, 'limit' => 20, 'starting_after' => 'in_9'])
        ->and(array_map(fn (Invoice $invoice) => $invoice->id, $page->invoices))->toBe(['in_2', 'in_1'])
        ->and($page->nextCursor)->toBe('in_1')
        ->and($page->invoices[1]->description)->toBe('1 × Pro (at $19.00 / month)')
        ->and($page->invoices[1]->createdAt->toIso8601ZuluString())->toBe('2026-10-01T12:00:00Z')
        ->and($page->invoices[0]->status)->toBe('open');
});

it('returns an empty page when Stripe knows no invoice for the cursor', function () {
    subscribe($this->acme);
    fakeStripeHttp(fn () => [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'param' => 'starting_after', 'message' => 'No such invoice']]]);

    $page = app(StripeBillingGateway::class)->invoices($this->acme, 20, 'in_unknown');

    expect($page->invoices)->toBe([])->and($page->nextCursor)->toBeNull();
});

it('reports other Stripe errors as billing unavailable', function () {
    subscribe($this->acme);
    fakeStripeHttp(fn () => [500, ['error' => ['type' => 'api_error', 'message' => 'Something went wrong']]]);

    app(StripeBillingGateway::class)->invoices($this->acme, 20, null);
})->throws(BillingUnavailable::class);

it('only passes on links to Stripe\'s own pages', function (string $url, bool $kept) {
    subscribe($this->acme);
    fakeStripeHttp(fn () => [
        'object' => 'list', 'url' => '/v1/invoices', 'has_more' => false,
        'data' => [stripeInvoice('in_1', ['hosted_invoice_url' => $url, 'invoice_pdf' => $url])],
    ]);

    $page = app(StripeBillingGateway::class)->invoices($this->acme, 20, null);
    $invoice = $page->invoices[0];

    expect($page->nextCursor)->toBeNull()
        ->and($invoice->hostedUrl)->toBe($kept ? $url : null)
        ->and($invoice->pdfUrl)->toBe($kept ? $url : null);
})->with([
    'hosted invoice page' => ['https://invoice.stripe.com/i/acct_1/test', true],
    'pdf' => ['https://pay.stripe.com/invoice/acct_1/test/pdf', true],
    'plain http' => ['http://invoice.stripe.com/i/acct_1/test', false],
    'another domain' => ['https://evil.example/stripe.com', false],
    'lookalike domain' => ['https://invoice.stripe.com.evil.example/i', false],
    'suffix without a dot' => ['https://notstripe.com/i', false],
    'credentials' => ['https://user@invoice.stripe.com/i', false],
    'javascript' => ['javascript:alert(1)', false],
]);

it('lets only roles with billing.manage list invoices', function (DefaultRole $role, int $status) {
    subscribe($this->acme);
    Sanctum::actingAs(addMember($this->acme, $role));

    $this->getJson('/api/billing/invoices')->assertStatus($status);

    expect($this->billing->invoiceRequests())->toHaveCount($status === 200 ? 1 : 0);
})->with([
    'owner' => [DefaultRole::Owner, 200],
    'admin' => [DefaultRole::Admin, 403],
    'member' => [DefaultRole::Member, 403],
]);

it('lets a custom role with billing.manage list invoices', function () {
    subscribe($this->acme);
    Sanctum::actingAs(addMember($this->acme, createRole($this->acme, 'Finance', [Permission::BillingManage])));

    $this->getJson('/api/billing/invoices')->assertOk();
});

it('answers 403 before validating the cursor for a role without billing.manage', function () {
    Sanctum::actingAs(addMember($this->acme, DefaultRole::Admin));

    $this->getJson('/api/billing/invoices?starting_after=abc')->assertForbidden();
});

it('needs a signed-in user', function () {
    $this->app['auth']->forgetGuards();

    $this->withHeaders(['Authorization' => ''])->getJson('/api/billing/invoices')->assertUnauthorized();
});

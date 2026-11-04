<?php

namespace App\Actions\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Billing\Exceptions\BillingUnavailable;
use App\Billing\InvoicePage;
use App\Models\Organization;

class ListInvoices
{
    public const PER_PAGE = 20;

    public function __construct(private readonly BillingGateway $gateway) {}

    /**
     * The organization's invoices, newest first. No provider call for an
     * organization that has never paid (no Stripe customer).
     *
     * @throws BillingUnavailable
     */
    public function handle(Organization $organization, ?string $startingAfter = null): InvoicePage
    {
        if (! $organization->hasStripeId()) {
            return InvoicePage::empty();
        }

        return $this->gateway->invoices($organization, self::PER_PAGE, $startingAfter);
    }
}

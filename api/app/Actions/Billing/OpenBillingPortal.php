<?php

namespace App\Actions\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

class OpenBillingPortal
{
    public function __construct(private readonly BillingGateway $gateway) {}

    /**
     * The hosted billing portal URL (card, invoices, cancel) for the organization.
     *
     * @throws ValidationException
     */
    public function handle(Organization $organization): string
    {
        if (! $organization->hasStripeId()) {
            throw ValidationException::withMessages(['billing' => 'This organization has no billing account yet. Subscribe to a plan first.']);
        }

        return $this->gateway->portalUrl($organization, config('app.frontend_url').'/billing');
    }
}

<?php

namespace App\Billing\Contracts;

use App\Billing\CreditPack;
use App\Billing\Exceptions\BillingUnavailable;
use App\Billing\InvoicePage;
use App\Billing\Plan;
use App\Models\Organization;
use App\Models\User;

/**
 * The only way the app talks to the payment provider. Payments happen on the
 * provider's hosted pages; what they change comes back through the webhook.
 */
interface BillingGateway
{
    /**
     * A hosted checkout page that subscribes the organization to the plan.
     * $user is who starts it (their email becomes the customer's billing email
     * if the organization isn't a customer yet).
     *
     * @throws BillingUnavailable
     */
    public function subscriptionCheckoutUrl(Organization $organization, User $user, Plan $plan, string $successUrl, string $cancelUrl): string;

    /**
     * A hosted checkout page for a one-time purchase of an AI credit pack. The
     * session carries the pack key and who started it (metadata), so the webhook
     * knows what was paid for.
     *
     * @throws BillingUnavailable
     */
    public function creditsCheckoutUrl(Organization $organization, User $user, CreditPack $pack, string $successUrl, string $cancelUrl): string;

    /**
     * The hosted portal for the organization's card, invoices and cancellation.
     * Only for an organization that is already a customer.
     *
     * @throws BillingUnavailable
     */
    public function portalUrl(Organization $organization, string $returnUrl): string;

    /**
     * A page of the organization's invoices, newest first, without drafts. Only
     * for an organization that is already a customer. $startingAfter is the
     * previous page's next cursor.
     *
     * @throws BillingUnavailable
     */
    public function invoices(Organization $organization, int $limit, ?string $startingAfter): InvoicePage;
}

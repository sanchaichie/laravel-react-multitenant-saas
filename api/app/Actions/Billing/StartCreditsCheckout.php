<?php

namespace App\Actions\Billing;

use App\Billing\Contracts\BillingGateway;
use App\Billing\CreditPacks;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StartCreditsCheckout
{
    public function __construct(
        private readonly CreditPacks $packs,
        private readonly BillingGateway $gateway,
    ) {}

    /**
     * The hosted checkout URL for buying an AI credit pack. Nothing changes here;
     * the webhook grants the credits once it's paid (GrantAiCredits).
     *
     * @throws ValidationException
     */
    public function handle(Organization $organization, User $user, string $packKey): string
    {
        $pack = $this->packs->find($packKey);

        if ($pack === null) {
            throw ValidationException::withMessages(['pack' => 'Choose a credit pack.']);
        }

        // With its own budget at 0, credits wouldn't buy any AI.
        if ($organization->ai_monthly_budget_usd !== null && (float) $organization->ai_monthly_budget_usd === 0.0) {
            throw ValidationException::withMessages(['pack' => 'AI is turned off for this organization (budget set to $0). Change the budget before buying credits.']);
        }

        if ($pack->priceId === null) {
            throw ValidationException::withMessages(['pack' => 'This credit pack isn\'t available yet.']);
        }

        $billingUrl = config('app.frontend_url').'/billing';

        return $this->gateway->creditsCheckoutUrl(
            $organization,
            $user,
            $pack,
            successUrl: $billingUrl.'?checkout=credits',
            cancelUrl: $billingUrl.'?checkout=canceled',
        );
    }
}

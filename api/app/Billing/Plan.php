<?php

namespace App\Billing;

/**
 * A plan from config/billing.php. Free has no Stripe price.
 */
final readonly class Plan
{
    public function __construct(
        public string $key,
        public string $name,
        public string $displayPrice,
        public ?string $priceId,
        public float $aiMonthlyBudgetUsd,
        public bool $isPaid,
    ) {}

    /**
     * @return array{key: string, name: string, display_price: string, ai_monthly_budget_usd: float, is_paid: bool}
     */
    public function toArray(): array
    {
        // No price id: the client only ever sends a plan key.
        return [
            'key' => $this->key,
            'name' => $this->name,
            'display_price' => $this->displayPrice,
            'ai_monthly_budget_usd' => $this->aiMonthlyBudgetUsd,
            'is_paid' => $this->isPaid,
        ];
    }
}

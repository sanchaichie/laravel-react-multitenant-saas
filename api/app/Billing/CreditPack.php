<?php

namespace App\Billing;

/**
 * An AI credit pack from config/billing.php: a one-time purchase.
 */
final readonly class CreditPack
{
    public function __construct(
        public string $key,
        public string $name,
        public string $displayPrice,
        public ?string $priceId,
        public float $amountUsd,
    ) {}

    /**
     * @return array{key: string, name: string, display_price: string, amount_usd: float, available: bool}
     */
    public function toArray(): array
    {
        // No price id: the client only ever sends a pack key.
        return [
            'key' => $this->key,
            'name' => $this->name,
            'display_price' => $this->displayPrice,
            'amount_usd' => $this->amountUsd,
            'available' => $this->priceId !== null,
        ];
    }
}

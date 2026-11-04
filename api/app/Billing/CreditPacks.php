<?php

namespace App\Billing;

/**
 * The AI credit packs in config/billing.php.
 */
class CreditPacks
{
    /**
     * @return array<string, CreditPack>
     */
    public function all(): array
    {
        $packs = [];

        foreach (config('billing.credit_packs') as $key => $pack) {
            $packs[$key] = new CreditPack(
                key: $key,
                name: $pack['name'],
                displayPrice: $pack['display_price'],
                priceId: $pack['price'] ?: null,
                amountUsd: (float) $pack['amount_usd'],
            );
        }

        return $packs;
    }

    public function find(string $key): ?CreditPack
    {
        return $this->all()[$key] ?? null;
    }
}

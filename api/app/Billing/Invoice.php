<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * An invoice as the provider reports it. Amounts are in the currency's smallest
 * unit (cents). The URLs are the provider's hosted invoice page and PDF.
 */
final readonly class Invoice
{
    public function __construct(
        public string $id,
        public ?string $number,
        // paid, open, void or uncollectible (drafts are never listed).
        public string $status,
        public int $total,
        public string $currency,
        public CarbonImmutable $createdAt,
        public ?string $description,
        public ?string $hostedUrl,
        public ?string $pdfUrl,
    ) {}
}

<?php

namespace App\Billing;

/**
 * One page of an organization's invoices, newest first. $nextCursor is the id to
 * pass as starting_after for the next page, or null on the last one.
 */
final readonly class InvoicePage
{
    /**
     * @param  list<Invoice>  $invoices
     */
    public function __construct(
        public array $invoices,
        public ?string $nextCursor,
    ) {}

    public static function empty(): self
    {
        return new self([], null);
    }
}

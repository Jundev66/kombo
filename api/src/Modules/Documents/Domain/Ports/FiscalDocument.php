<?php

declare(strict_types=1);

namespace Modules\Documents\Domain\Ports;

/**
 * The door left open in case a tenant is ever certified.
 *
 * There is deliberately no real implementation. This system issues DELIVERY
 * NOTES: commercial documents, not fiscal ones. It computes no VAT as a fiscal
 * debit, keeps no sales ledger, and numbers from no authority-assigned range.
 *
 * A delivery note does not replace an invoice or remove the tenant's tax
 * obligations — issuing invoices requires the means authorised by SENIAT. This
 * port only keeps the road open: an adapter can be plugged in without touching
 * `Counter` or `Orders`.
 */
interface FiscalDocument
{
    /** Can this tenant issue fiscal documents? */
    public function isAvailable(): bool;

    /**
     * Issues an order's fiscal document and returns its identification.
     *
     * @return array{number: string, controlNumber: string}|null
     */
    public function issueFor(string $orderId): ?array;
}

<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * One recorded price change as the panel shows it, with the id that places it in the paging order. Amounts are in
 * grosze, the rate in basis points.
 */
final readonly class PriceHistoryItem
{
    /**
     * @param string      $id        the id of the history entry (UUID); within one second the later change has the greater id
     * @param string      $source    `staff`, `seed` or `tax_reconciliation`
     * @param string|null $actorName the staff member of a `staff` change, null for a system source
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $changedAt,
        public int $gross,
        public int $net,
        public int $vat,
        public int $vatRate,
        public string $taxClass,
        public string $source,
        public ?string $actorName,
    ) {
    }
}

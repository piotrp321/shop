<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Application\Query\DiscountCodeCursor;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;

/**
 * The discount codes for the list of the panel, newest first.
 */
interface DiscountCodeReader
{
    /**
     * @param DiscountCodeStatus|null $status     only the codes in this status at now
     * @param string|null             $codePrefix only the codes whose text starts with it (A–Z, 0–9 and the dash)
     *
     * @return list<ListedDiscountCode> ordered by creation descending, then id, after the cursor; at most $limit
     */
    public function page(?DiscountCodeStatus $status, ?string $codePrefix, \DateTimeImmutable $now, ?DiscountCodeCursor $after, int $limit): array;
}

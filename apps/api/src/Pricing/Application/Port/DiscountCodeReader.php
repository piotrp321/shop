<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Application\Query\DiscountCodeCursor;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Shared\Domain\BoundedCount;

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

    /**
     * How many codes match the filters of {@see page()}, whatever the cursor: counted up to 10 000 (at most 10 001
     * rows are read), never the whole table.
     *
     * @param DiscountCodeStatus|null $status     only the codes in this status at now
     * @param string|null             $codePrefix only the codes whose text starts with it (A–Z, 0–9 and the dash)
     */
    public function count(?DiscountCodeStatus $status, ?string $codePrefix, \DateTimeImmutable $now): BoundedCount;
}

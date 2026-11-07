<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

/**
 * The current time, to the microsecond. The writes of promotions and of prices take it only once they hold the lock
 * of the promotion writes (PromotionWriteLock), so a price written before a promotion "from now" is before its start.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}

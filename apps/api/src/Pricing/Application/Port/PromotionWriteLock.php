<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

/**
 * One lock for all writes of promotions, held until the end of the transaction of the write. A write of a promotion
 * takes it exclusively, so the writes of promotions run one after another and the rule that a variant is in at most
 * one promotion at a time (Q4) is checked on what the others committed. A change of a price from the panel takes it
 * shared (Q13): price changes do not wait for each other, but a promotion "from now" and a price change of one of its
 * variants run one after another. Both read the clock only after the lock.
 *
 * Must be called inside the transaction of the write (command.bus).
 */
interface PromotionWriteLock
{
    public function exclusive(): void;

    public function shared(): void;
}

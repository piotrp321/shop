<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

/**
 * Removes one batch of at most $batchSize expired carts and returns how many it removed. command.bus wraps a handler
 * in one transaction, so each batch is dispatched on its own (cart:purge-expired repeats it until it returns 0), and
 * a failing batch cannot undo the batches removed before it.
 */
final readonly class PurgeExpiredCarts
{
    public const int DEFAULT_BATCH_SIZE = 1000;

    public function __construct(public int $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be a positive integer.');
        }
    }
}

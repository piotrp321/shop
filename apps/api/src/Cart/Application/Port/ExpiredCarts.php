<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

/**
 * The carts past their expiry time, which are removed for good (their lines with them).
 */
interface ExpiredCarts
{
    /**
     * Removes at most $limit carts with an expiry time before $now, the earliest first, and returns how many it
     * removed. Must run inside a transaction (command.bus has doctrine_transaction). A cart that a concurrent
     * command holds locked is left alone, and so is one that command has renewed.
     */
    public function purgeBatch(\DateTimeImmutable $now, int $limit): int;
}

<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

/**
 * The persistence session in which the prices are read and saved. A process that goes through many prices (the
 * tax reconciliation) resets it after each batch, so it keeps nothing it has read, and after a refused save, which
 * may leave the session unusable (Doctrine closes the entity manager), so the next read and save work.
 */
interface PersistenceSession
{
    public function reset(): void;
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\PersistenceSession;

/**
 * A session a failed write leaves unusable, like Doctrine's closed entity manager, until it is reset.
 */
final class InMemoryPersistenceSession implements PersistenceSession
{
    public bool $open = true;
    public int $resets = 0;

    public function reset(): void
    {
        $this->open = true;
        ++$this->resets;
    }

    public function close(): void
    {
        $this->open = false;
    }
}

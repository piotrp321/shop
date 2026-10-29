<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Port\SearchIndexRebuildLock;

final class FakeSearchIndexRebuildLock implements SearchIndexRebuildLock
{
    public bool $held = false;
    public int $releases = 0;

    public function __construct(private readonly bool $takenElsewhere = false)
    {
    }

    public function tryAcquire(): bool
    {
        if ($this->takenElsewhere || $this->held) {
            return false;
        }

        return $this->held = true;
    }

    public function release(): void
    {
        ++$this->releases;
        $this->held = false;
    }
}

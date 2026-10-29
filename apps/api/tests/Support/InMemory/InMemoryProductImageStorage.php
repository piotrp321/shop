<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\ProductImageStorage;

final class InMemoryProductImageStorage implements ProductImageStorage
{
    /** @var list<string> keys, one entry per store() call */
    public array $stored = [];
    public int $purges = 0;

    public function store(string $contents): string
    {
        return $this->stored[] = 'originals/'.hash('sha256', $contents).'.jpg';
    }

    public function purge(): void
    {
        ++$this->purges;
    }
}

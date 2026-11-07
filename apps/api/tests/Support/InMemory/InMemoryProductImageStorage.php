<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Application\Port\ProductImageStorageUnavailable;

final class InMemoryProductImageStorage implements ProductImageStorage
{
    /** @var list<string> keys, one entry per store() call */
    public array $stored = [];
    /** @var list<string> keys of originals that exist without a store() call */
    public array $existing = [];
    public bool $unavailable = false;
    public int $purges = 0;

    public function store(string $contents): string
    {
        return $this->stored[] = 'originals/'.hash('sha256', $contents).'.jpg';
    }

    public function exists(string $key): bool
    {
        if ($this->unavailable) {
            throw ProductImageStorageUnavailable::because(new \RuntimeException('down'));
        }

        return \in_array($key, $this->stored, true) || \in_array($key, $this->existing, true);
    }

    public function purge(): void
    {
        ++$this->purges;
    }
}

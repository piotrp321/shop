<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\ProductTableStatistics;

final class FixedProductTableStatistics implements ProductTableStatistics
{
    public int $estimates = 0;
    public int $refreshes = 0;

    public function __construct(private readonly ?int $estimate)
    {
    }

    public function estimatedProductCount(): ?int
    {
        ++$this->estimates;

        return $this->estimate;
    }

    public function refresh(): void
    {
        ++$this->refreshes;
    }
}

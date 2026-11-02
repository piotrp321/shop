<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Application\Port\ProductCatalog;
use App\Inventory\Domain\Stock\ProductId;

final class InMemoryProductCatalog implements ProductCatalog
{
    /** @var array<string, true> */
    private array $products = [];

    /** @var list<string> the ids of every exists() call */
    public array $asked = [];

    public function add(string ...$productIds): void
    {
        foreach ($productIds as $id) {
            $this->products[$id] = true;
        }
    }

    public function exists(ProductId $id): bool
    {
        $this->asked[] = $id->toString();

        return isset($this->products[$id->toString()]);
    }
}

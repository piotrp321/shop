<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Application\Port\VariantCatalog;
use App\Inventory\Domain\Stock\VariantId;

final class InMemoryVariantCatalog implements VariantCatalog
{
    /** @var array<string, true> */
    private array $variants = [];

    /** @var list<string> the ids of every exists() call */
    public array $asked = [];

    public function add(string ...$variantIds): void
    {
        foreach ($variantIds as $id) {
            $this->variants[$id] = true;
        }
    }

    public function exists(VariantId $id): bool
    {
        $this->asked[] = $id->toString();

        return isset($this->variants[$id->toString()]);
    }
}

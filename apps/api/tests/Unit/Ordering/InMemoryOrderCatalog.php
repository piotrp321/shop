<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\CatalogVariant;
use App\Ordering\Application\Port\OrderCatalog;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\VariantId;

final class InMemoryOrderCatalog implements OrderCatalog
{
    /** @var array<string, CatalogVariant> */
    private array $variants = [];
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(private readonly Journal $journal)
    {
    }

    public function variant(string $variantId, string $productId, string $name, string $label): self
    {
        $this->variants[$variantId] = new CatalogVariant(ProductId::fromString($productId), $name, $label);

        return $this;
    }

    public function variants(array $variantIds): array
    {
        $this->journal->record('catalog');
        $this->asked[] = array_map(static fn (VariantId $id): string => $id->toString(), $variantIds);

        $found = [];
        foreach ($variantIds as $id) {
            $variant = $this->variants[$id->toString()] ?? null;
            if (null !== $variant) {
                $found[$id->toString()] = $variant;
            }
        }

        return $found;
    }
}

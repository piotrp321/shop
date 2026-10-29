<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\SlugAlreadyTaken;

/**
 * Like the database, it holds one product per id (save overwrites) and refuses a slug used by another product.
 */
final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<string, Product> */
    private array $products = [];

    public function save(Product $product): void
    {
        foreach ($this->products as $id => $stored) {
            if ($id !== $product->id()->toString() && $stored->slug()->value() === $product->slug()->value()) {
                throw new SlugAlreadyTaken();
            }
        }

        $this->products[$product->id()->toString()] = $product;
    }

    public function get(ProductId $id): Product
    {
        return $this->products[$id->toString()] ?? throw new ProductNotFound();
    }

    public function all(): iterable
    {
        return array_values($this->products);
    }

    public function count(): int
    {
        return \count($this->products);
    }

    public function countWithoutImages(): int
    {
        return \count(array_filter($this->products, static fn (Product $product): bool => $product->images()->isEmpty()));
    }
}

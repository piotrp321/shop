<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\VariantAlreadyExists;

/**
 * Like the database, it holds one product per id (save overwrites) and refuses a slug or a variant id used by another
 * product. get() returns a copy at the stored version; every save of a stored product raises the version (the
 * database skips an unchanged write without a view, which no test of a handler relies on) and a stale expected
 * version is refused. The metadata of the last save is kept per id.
 */
final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<string, Product> */
    private array $products = [];
    /** @var array<string, int> */
    private array $versions = [];
    /** @var array<string, array{actorId: ?string, at: \DateTimeImmutable}> */
    public array $lastWrites = [];

    public function save(Product $product, ?int $expectedVersion, ?string $actorId, \DateTimeImmutable $at): void
    {
        $id = $product->id()->toString();
        if (null !== $expectedVersion && isset($this->versions[$id]) && $this->versions[$id] !== $expectedVersion) {
            throw new ProductModifiedConcurrently();
        }
        foreach ($this->products as $storedId => $stored) {
            if ($storedId !== $id && $stored->slug()->value() === $product->slug()->value()) {
                throw new SlugAlreadyTaken();
            }
        }
        foreach ($this->products as $storedId => $stored) {
            if ($storedId === $id) {
                continue;
            }
            $taken = array_map(static fn (ProductVariant $v): string => $v->id()->toString(), $stored->variants()->toList());
            foreach ($product->variants()->toList() as $variant) {
                if (\in_array($variant->id()->toString(), $taken, true)) {
                    throw new VariantAlreadyExists();
                }
            }
        }

        $this->versions[$id] = ($this->versions[$id] ?? 0) + 1;
        $this->products[$id] = self::copy($product, $this->versions[$id]);
        $this->lastWrites[$id] = ['actorId' => $actorId, 'at' => $at];
    }

    public function get(ProductId $id): Product
    {
        $product = $this->products[$id->toString()] ?? throw new ProductNotFound();

        return self::copy($product, $this->versions[$id->toString()]);
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

    public function version(ProductId $id): ?int
    {
        return $this->versions[$id->toString()] ?? null;
    }

    private static function copy(Product $product, int $version): Product
    {
        return Product::reconstitute(
            $product->id(),
            $product->name(),
            $product->slug(),
            $product->shortDescription(),
            $product->categories(),
            $product->taxClass(),
            $product->images(),
            $product->variants(),
            $product->status(),
            $product->firstPublishedAt(),
            $version,
        );
    }
}

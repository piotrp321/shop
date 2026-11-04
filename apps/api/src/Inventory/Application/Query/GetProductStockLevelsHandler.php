<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\StockItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Transitional (removed with GET /api/v1/availability in task VARIANTS-4): keeps the product contract of the store
 * list and of the cart while stock is kept per variant.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductStockLevelsHandler
{
    public function __construct(
        private ProductDirectory $products,
        private StockItemRepository $stock,
    ) {
    }

    /**
     * The variants of all the products from Catalog with one call, the stocks of the single variants with one read.
     * A product with exactly one variant has the stock of that variant (none stored: nothing available); a product
     * with more variants or without variants (unknown to Catalog) has nothing available. Every requested product has
     * a level, in the order of the request; a repeated id answers once.
     *
     * @return list<ProductStockLevel>
     *
     * @throws InvalidStockLevelsQuery
     */
    public function __invoke(GetProductStockLevels $query): array
    {
        $count = \count($query->productIds);
        if ($count < 1 || $count > GetProductStockLevels::MAX_PRODUCTS) {
            throw InvalidStockLevelsQuery::productCountOutOfRange();
        }

        $ids = [];
        foreach ($query->productIds as $raw) {
            try {
                $id = ProductId::fromString($raw);
            } catch (\InvalidArgumentException) {
                throw InvalidStockLevelsQuery::notAProductId();
            }
            $ids[$id->toString()] = $id;
        }

        $single = [];
        foreach ($this->products->variantsOf(array_values($ids)) as $productId => $variants) {
            if (1 === \count($variants)) {
                $single[$productId] = $variants[0]->id;
            }
        }

        $stored = [];
        if ([] !== $single) {
            foreach ($this->stock->findMany(array_values($single)) as $item) {
                $stored[$item->id()->toString()] = $item;
            }
        }

        $levels = [];
        foreach (array_keys($ids) as $productId) {
            $variantId = $single[$productId] ?? null;
            $available = null === $variantId ? 0 : ($stored[$variantId->toString()] ?? null)?->available() ?? 0;
            $levels[] = new ProductStockLevel($productId, $available, AvailabilityLevel::of($available)->value);
        }

        return $levels;
    }
}

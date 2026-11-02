<?php

declare(strict_types=1);

namespace App\Inventory\Application\Demo;

use App\Inventory\Application\Command\CreateEmptyStock;
use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Application\Port\CatalogProduct;
use App\Inventory\Application\Port\ProductsForInventory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gives every Catalog product its demo stock through the `command.bus` (injected by the parameter name): a receipt
 * with the source `seed` and the key `seed:<productId>` for a stock above 0, an empty stock record for 0. Products
 * are read in batches, so the catalog is never held in memory.
 */
final readonly class DemoStockSeeder
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private ProductsForInventory $products,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return list<string> slugs of the products no pattern matches, in id order
     */
    public function unmatchedSlugs(DemoStockList $stock): array
    {
        $unmatched = [];
        foreach ($this->allProducts() as $product) {
            if (null === $stock->quantityFor($product->slug)) {
                $unmatched[] = $product->slug;
            }
        }

        return $unmatched;
    }

    /**
     * Each stock is set in its own transaction. The products must have no stock yet (see InventoryReset).
     *
     * @return int the number of stocks set
     *
     * @throws \RuntimeException at the first product no pattern matches
     */
    public function seed(DemoStockList $stock): int
    {
        $count = 0;
        foreach ($this->allProducts() as $product) {
            $quantity = $stock->quantityFor($product->slug)
                ?? throw new \RuntimeException(\sprintf('No demo stock pattern matches the product "%s".', $product->slug));
            $productId = $product->id->toString();
            $this->commandBus->dispatch(
                $quantity > 0
                    ? ReceiveStock::bySeed($productId, $quantity, 'seed:'.$productId)
                    : new CreateEmptyStock($productId),
            );
            ++$count;
        }

        return $count;
    }

    /**
     * @return iterable<CatalogProduct>
     */
    private function allProducts(): iterable
    {
        $after = null;
        do {
            $batch = $this->products->page($after, self::BATCH_SIZE);
            yield from $batch;
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id;
        } while (self::BATCH_SIZE === \count($batch));
    }
}

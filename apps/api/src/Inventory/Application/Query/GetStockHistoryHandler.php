<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\ProductCatalog;
use App\Inventory\Application\Port\StockHistoryReader;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\ProductNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetStockHistoryHandler
{
    public function __construct(
        private ProductCatalog $products,
        private StockHistoryReader $history,
    ) {
    }

    /**
     * Existence of the product is decided by Catalog, like in ReceiveStock: a product that exists but has no stock
     * record has an empty history. One entry more than the page is read to know whether a next page exists.
     *
     * @throws InvalidStockHistoryCursor
     * @throws ProductNotFound
     */
    public function __invoke(GetStockHistory $query): StockHistoryPage
    {
        $after = null === $query->after ? null : StockHistoryCursor::decode($query->after);
        $productId = ProductId::fromString($query->productId);
        if (!$this->products->exists($productId)) {
            throw new ProductNotFound();
        }

        $items = $this->history->page($productId, $after, $query->perPage + 1);
        if (\count($items) <= $query->perPage) {
            return new StockHistoryPage($items, null);
        }

        $items = \array_slice($items, 0, $query->perPage);

        return new StockHistoryPage($items, StockHistoryCursor::after($items[$query->perPage - 1])->encode());
    }
}

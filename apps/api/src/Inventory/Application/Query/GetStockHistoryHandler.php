<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Inventory\Application\Port\StockHistoryReader;
use App\Inventory\Application\Port\VariantCatalog;
use App\Inventory\Domain\Stock\VariantId;
use App\Inventory\Domain\Stock\VariantNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetStockHistoryHandler
{
    public function __construct(
        private VariantCatalog $variants,
        private StockHistoryReader $history,
    ) {
    }

    /**
     * Existence of the variant is decided by Catalog, like in ReceiveStock: a variant that exists but has no stock
     * record has an empty history. One entry more than the page is read to know whether a next page exists.
     *
     * @throws InvalidStockHistoryCursor
     * @throws VariantNotFound
     */
    public function __invoke(GetStockHistory $query): StockHistoryPage
    {
        $after = null === $query->after ? null : StockHistoryCursor::decode($query->after);
        $variantId = VariantId::fromString($query->variantId);
        if (!$this->variants->exists($variantId)) {
            throw new VariantNotFound();
        }

        $items = $this->history->page($variantId, $after, $query->perPage + 1);
        if (\count($items) <= $query->perPage) {
            return new StockHistoryPage($items, null);
        }

        $items = \array_slice($items, 0, $query->perPage);

        return new StockHistoryPage($items, StockHistoryCursor::after($items[$query->perPage - 1])->encode());
    }
}

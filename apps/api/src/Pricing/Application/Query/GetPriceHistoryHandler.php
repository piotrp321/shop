<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\PriceHistoryReader;
use App\Pricing\Application\Port\ProductTaxClasses;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetPriceHistoryHandler
{
    public function __construct(
        private ProductTaxClasses $products,
        private PriceHistoryReader $history,
    ) {
    }

    /**
     * Existence of the product is decided by Catalog, like in SetProductPrice: a product that exists but has never
     * had a price has an empty history. One entry more than the page is read to know whether a next page exists.
     *
     * @throws InvalidPriceHistoryCursor
     * @throws ProductNotFound
     */
    public function __invoke(GetPriceHistory $query): PriceHistoryPage
    {
        $after = null === $query->after ? null : PriceHistoryCursor::decode($query->after);
        $productId = ProductId::fromString($query->productId);
        if (null === $this->products->taxClassOf($productId)) {
            throw new ProductNotFound();
        }

        $items = $this->history->page($productId, $after, $query->perPage + 1);
        if (\count($items) <= $query->perPage) {
            return new PriceHistoryPage($items, null);
        }

        $items = \array_slice($items, 0, $query->perPage);

        return new PriceHistoryPage($items, PriceHistoryCursor::after($items[$query->perPage - 1])->encode());
    }
}

<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\PriceHistoryReader;
use App\Pricing\Application\Port\VariantTaxClasses;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetPriceHistoryHandler
{
    public function __construct(
        private VariantTaxClasses $variants,
        private PriceHistoryReader $history,
    ) {
    }

    /**
     * Existence of the variant is decided by Catalog, like in SetVariantPrice: a variant that exists but has never
     * had a price has an empty history. One entry more than the page is read to know whether a next page exists.
     *
     * @throws InvalidPriceHistoryCursor
     * @throws VariantNotFound
     */
    public function __invoke(GetPriceHistory $query): PriceHistoryPage
    {
        $after = null === $query->after ? null : PriceHistoryCursor::decode($query->after);
        $variantId = VariantId::fromString($query->variantId);
        if (null === $this->variants->taxClassOf($variantId)) {
            throw new VariantNotFound();
        }

        $items = $this->history->page($variantId, $after, $query->perPage + 1);
        if (\count($items) <= $query->perPage) {
            return new PriceHistoryPage($items, null);
        }

        $items = \array_slice($items, 0, $query->perPage);

        return new PriceHistoryPage($items, PriceHistoryCursor::after($items[$query->perPage - 1])->encode());
    }
}

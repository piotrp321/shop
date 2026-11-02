<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\ProductsForPricingSource;
use App\Catalog\Domain\Product\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListProductsForPricingHandler
{
    public function __construct(private ProductsForPricingSource $source)
    {
    }

    /**
     * @return list<ProductForPricing>
     *
     * @throws \InvalidArgumentException when the limit is out of range or the cursor is not a UUID
     */
    public function __invoke(ListProductsForPricing $query): array
    {
        if ($query->limit < 1 || $query->limit > ListProductsForPricing::MAX_LIMIT) {
            throw new \InvalidArgumentException('The batch limit must be between 1 and 1000.');
        }

        return $this->source->page(null === $query->after ? null : ProductId::fromString($query->after), $query->limit);
    }
}

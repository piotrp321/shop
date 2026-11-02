<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\ProductsForPricingSource;
use App\Catalog\Domain\Product\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductTaxClassHandler
{
    public function __construct(private ProductsForPricingSource $source)
    {
    }

    /**
     * @throws \InvalidArgumentException when the id is not a UUID
     */
    public function __invoke(GetProductTaxClass $query): ?string
    {
        return $this->source->taxClassOf(ProductId::fromString($query->productId))?->value;
    }
}

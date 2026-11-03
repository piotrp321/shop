<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\ProductsForInventorySource;
use App\Catalog\Domain\Product\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductExistsHandler
{
    public function __construct(private ProductsForInventorySource $source)
    {
    }

    /**
     * @throws \InvalidArgumentException when the id is not a UUID
     */
    public function __invoke(GetProductExists $query): bool
    {
        return $this->source->exists(ProductId::fromString($query->productId));
    }
}

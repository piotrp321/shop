<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\VariantSource;
use App\Catalog\Domain\Product\VariantId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetVariantHandler
{
    public function __construct(private VariantSource $source)
    {
    }

    /**
     * @throws \InvalidArgumentException when the id is not a UUID
     */
    public function __invoke(GetVariant $query): ?VariantInfo
    {
        $row = $this->source->find(VariantId::fromString($query->variantId));

        return null === $row ? null : new VariantInfo($row['id'], $row['productId'], $row['taxClass']);
    }
}

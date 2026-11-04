<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\VariantSource;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListProductVariantsHandler
{
    public function __construct(private VariantSource $source)
    {
    }

    /**
     * One read for all the products. The lists follow the order of the request; a repeated id answers once,
     * a product that does not exist is left out.
     *
     * @return list<ProductVariantList>
     *
     * @throws \InvalidArgumentException when no id or more than 100 are given, or an id is not a UUID
     */
    public function __invoke(ListProductVariants $query): array
    {
        $count = \count($query->productIds);
        if ($count < 1 || $count > ListProductVariants::MAX_PRODUCTS) {
            throw new \InvalidArgumentException(\sprintf('Between 1 and %d products must be given.', ListProductVariants::MAX_PRODUCTS));
        }

        $ids = [];
        foreach ($query->productIds as $raw) {
            $id = ProductId::fromString($raw);
            $ids[$id->toString()] = $id;
        }

        $found = [];
        foreach ($this->source->ofProducts(array_values($ids)) as $row) {
            $found[$row['productId']] = $row;
        }

        $lists = [];
        foreach (array_keys($ids) as $key) {
            $row = $found[$key] ?? null;
            if (null === $row) {
                continue;
            }
            $lists[] = new ProductVariantList(
                $key,
                $row['axes'],
                array_map(
                    static function (array $variant): VariantOption {
                        $id = VariantId::fromString($variant['id']);

                        return new VariantOption($id->toString(), new ProductVariant($id, VariantValues::fromList($variant['values']))->label());
                    },
                    $row['variants'],
                ),
            );
        }

        return $lists;
    }
}

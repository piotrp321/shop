<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\VariantId;

/**
 * What other modules need to know about variants, read straight from the Catalog tables. Every method is one read and
 * answers for drafts too; `published` tells the queries of the store which rows to leave out.
 */
interface VariantSource
{
    /**
     * A variant that does not exist is left out, the order is unspecified. The main image is the first of the
     * images of the product.
     *
     * @param list<VariantId> $ids
     *
     * @return list<array{id: string, productId: string, name: string, values: list<string>, mainImage: array{key: string, alt: string}|null, published: bool}>
     */
    public function summaries(array $ids): array;

    /**
     * @return array{id: string, productId: string, taxClass: string}|null
     */
    public function find(VariantId $id): ?array;

    /**
     * A product that does not exist is left out, the order of products is unspecified; the variants of a product
     * are in position order.
     *
     * @param list<ProductId> $ids
     *
     * @return list<array{productId: string, axes: list<string>, taxClass: string, published: bool, variants: list<array{id: string, values: list<string>}>}>
     */
    public function ofProducts(array $ids): array;

    /**
     * @param int $limit at least 1
     *
     * @return list<array{id: string, productSlug: string, values: list<string>, taxClass: string}> ordered by id, starting after $after
     */
    public function page(?VariantId $after, int $limit): array;
}

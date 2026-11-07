<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Product\ProductId;

/**
 * The stored state of a product with the metadata of its last write, for the edit form of the panel. Also drafts.
 */
interface ProductEditSource
{
    /**
     * @return array{
     *     id: string,
     *     version: int,
     *     status: string,
     *     firstPublishedAt: \DateTimeImmutable|null,
     *     name: string,
     *     slug: string,
     *     shortDescription: string,
     *     mainCategoryId: string,
     *     additionalCategoryIds: list<string>,
     *     taxClass: string,
     *     images: list<array{key: string, alt: string}>,
     *     axes: list<string>,
     *     variants: list<array{id: string, values: list<string>}>,
     *     updatedAt: \DateTimeImmutable
     * }|null null when there is no such product; images and variants in position order, additional categories by id
     */
    public function find(ProductId $id): ?array;
}

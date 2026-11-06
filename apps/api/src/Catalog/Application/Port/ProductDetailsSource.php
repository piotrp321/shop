<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Product\Slug;

/**
 * The data of the product page, read straight from the Catalog tables.
 */
interface ProductDetailsSource
{
    /**
     * @return array{
     *     id: string,
     *     slug: string,
     *     name: string,
     *     shortDescription: string,
     *     breadcrumb: list<array{id: string, name: string}>,
     *     images: list<array{key: string, alt: string}>,
     *     axes: list<string>,
     *     variants: list<array{id: string, values: list<string>}>
     * }|null null when no product has the slug; breadcrumb from the root to the main category, images and
     *        variants in position order
     */
    public function bySlug(Slug $slug): ?array;
}

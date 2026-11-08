<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

use App\Catalog\Domain\Product\ProductStatus;

/**
 * A product as stored in the search index (a read model, never a source of business decisions). A draft has a
 * document too, a tombstone with its version that the queries of the store never match (decision A3).
 */
final readonly class ProductDocument
{
    /**
     * @param list<string>                         $categoryIds         every category of the product, the main one first
     * @param list<string>                         $categoryAncestorIds the product's categories and all their ancestors, without duplicates
     * @param array{key: string, alt: string}|null $mainImage
     * @param list<string>                         $categoryNames       the names of the product's categories and all their ancestors
     * @param list<VariantDocument>                $variants            in Catalog's order
     * @param int|null                             $fromGross           the "from" price of the offer in grosze, null without a purchasable variant
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $name,
        public string $slug,
        public string $shortDescription,
        public string $mainCategoryId,
        public array $categoryIds,
        public array $categoryAncestorIds,
        public ?array $mainImage,
        public array $categoryNames,
        public array $variants,
        public ?int $fromGross,
        public ProductStatus $status,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * A product as stored in the search index (a read model, never a source of business decisions).
 */
final readonly class ProductDocument
{
    /**
     * @param list<string>                         $categoryIds         every category of the product, the main one first
     * @param list<string>                         $categoryAncestorIds the product's categories and all their ancestors, without duplicates
     * @param array{key: string, alt: string}|null $mainImage
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
    ) {
    }
}

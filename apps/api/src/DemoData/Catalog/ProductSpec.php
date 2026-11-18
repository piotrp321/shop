<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

/**
 * A generated product with its variants: the rows of catalog_product, catalog_product_variant and
 * catalog_product_category, plus what later steps need (type and photo group).
 */
final readonly class ProductSpec
{
    /**
     * @param list<string>                $additionalCategoryIds
     * @param list<string>                $axes
     * @param non-empty-list<VariantSpec> $variants
     * @param 'draft'|'published'         $status
     */
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
        public string $slug,
        public string $shortDescription,
        public string $primaryCategoryId,
        public array $additionalCategoryIds,
        public string $taxClass,
        public string $status,
        public ?\DateTimeImmutable $firstPublishedAt,
        public \DateTimeImmutable $updatedAt,
        public array $axes,
        public array $variants,
        public string $typeKey,
        public string $photoGroup,
        public bool $imageless,
    ) {
    }
}

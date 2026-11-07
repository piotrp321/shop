<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * A product as the edit form of the panel needs it, with the version a save is based on.
 */
final readonly class ProductEditView
{
    /**
     * @param string                   $status                `draft` or `published`
     * @param bool                     $slugLocked            whether the product was ever published, so its slug cannot change
     * @param list<string>             $additionalCategoryIds
     * @param list<ProductEditImage>   $images                in position order, previews in the `thumbnail` size
     * @param list<string>             $variantAxes
     * @param list<ProductEditVariant> $variants              in presentation order
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $status,
        public ?\DateTimeImmutable $firstPublishedAt,
        public string $name,
        public string $slug,
        public bool $slugLocked,
        public string $shortDescription,
        public string $mainCategoryId,
        public array $additionalCategoryIds,
        public string $taxClass,
        public array $images,
        public array $variantAxes,
        public array $variants,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}

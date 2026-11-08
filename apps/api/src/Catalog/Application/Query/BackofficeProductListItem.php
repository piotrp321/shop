<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * One row of the product list of the panel.
 */
final readonly class BackofficeProductListItem
{
    /**
     * @param string $status `draft` or `published`
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $status,
        public ProductCategoryRef $mainCategory,
        public int $variantCount,
        public ?ImageView $image,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}

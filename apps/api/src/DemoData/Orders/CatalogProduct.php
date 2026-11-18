<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A product of the tail as an order sees it: whether and since when it is published, its department (for the season),
 * its name and tax class (copied to the lines) and its variants.
 */
final readonly class CatalogProduct
{
    /**
     * @param list<CatalogVariant> $variants in their positions
     */
    public function __construct(
        public int $index,
        public string $id,
        public string $name,
        public bool $published,
        public ?\DateTimeImmutable $firstPublishedAt,
        public string $department,
        public string $taxClass,
        public array $variants,
    ) {
    }
}

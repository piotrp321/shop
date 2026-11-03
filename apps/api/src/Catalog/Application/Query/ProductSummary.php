<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * A product as a list of other modules shows it: its name and main image, null when it has no images.
 */
final readonly class ProductSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public ?ImageView $image,
    ) {
    }
}

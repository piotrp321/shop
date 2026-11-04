<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

/**
 * The main image of a product as Catalog gives it: one predefined size, signed URLs of its variants by format.
 */
final readonly class ProductImage
{
    /**
     * @param array<string, list<array{width: int, url: string}>> $variants URLs by output format, widths ascending
     */
    public function __construct(
        public string $alt,
        public string $size,
        public string $aspectRatio,
        public array $variants,
    ) {
    }
}

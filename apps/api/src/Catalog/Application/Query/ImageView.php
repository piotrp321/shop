<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * A product image of one predefined size, with signed URLs of its variants grouped by output format.
 */
final readonly class ImageView
{
    /**
     * @param array<string, list<array{width: int, url: string}>> $variants URLs by output format (the file extension), widths ascending
     */
    public function __construct(
        public string $alt,
        public string $size,
        public string $aspectRatio,
        public array $variants,
    ) {
    }
}

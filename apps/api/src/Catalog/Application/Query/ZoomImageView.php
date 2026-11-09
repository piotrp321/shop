<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * A product image in the `zoom` size: the whole image without cropping, so it has no aspect ratio. Signed URLs of its
 * variants are grouped by output format.
 */
final readonly class ZoomImageView
{
    /**
     * @param array<string, list<array{width: int, url: string}>> $variants URLs by output format (the file extension), widths ascending
     */
    public function __construct(
        public string $alt,
        public string $size,
        public array $variants,
    ) {
    }
}

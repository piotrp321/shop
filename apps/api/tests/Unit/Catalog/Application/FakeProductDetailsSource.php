<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\ProductDetailsSource;
use App\Catalog\Domain\Product\Slug;

/**
 * Returns one published product with the given images and counts the reads.
 */
final class FakeProductDetailsSource implements ProductDetailsSource
{
    public int $reads = 0;

    /**
     * @param list<array{key: string, alt: string}> $images
     */
    public function __construct(private readonly array $images)
    {
    }

    public function bySlug(Slug $slug): array
    {
        ++$this->reads;

        return [
            'id' => '0192f0c4-7c1a-7c4e-9a6e-000000000010',
            'slug' => 'buty',
            'name' => 'Buty',
            'shortDescription' => '',
            'breadcrumb' => [],
            'images' => $this->images,
            'axes' => [],
            'variants' => [['id' => 'c0000000-0000-4000-8000-000000000001', 'values' => []]],
            'published' => true,
        ];
    }
}

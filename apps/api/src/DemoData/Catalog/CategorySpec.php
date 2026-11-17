<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

/**
 * A row of catalog_category. `existing` marks a department that is already in the table (the seed catalog), which the
 * generator reuses and does not write again.
 */
final readonly class CategorySpec
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $parentId,
        public string $slugPath,
        public string $path,
        public int $position,
        public bool $existing,
    ) {
    }
}

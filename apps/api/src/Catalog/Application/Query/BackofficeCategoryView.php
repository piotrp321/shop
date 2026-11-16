<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * One category as a change of the panel saved it: `path` is its address in the store.
 */
final readonly class BackofficeCategoryView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $path,
        public ?string $parentId,
        public int $version,
    ) {
    }
}

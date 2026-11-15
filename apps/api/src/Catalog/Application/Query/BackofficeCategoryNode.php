<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Shared\Domain\BoundedCount;

/**
 * A category of the tree of the panel: the node of the store with the version a change is based on and, when asked
 * for, its direct products (main or additional, drafts too), counted up to a limit.
 */
final readonly class BackofficeCategoryNode
{
    /**
     * @param list<BackofficeCategoryNode> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $path,
        public int $version,
        public ?BoundedCount $productCount,
        public array $children,
    ) {
    }
}

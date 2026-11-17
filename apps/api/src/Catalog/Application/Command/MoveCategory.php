<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Under another parent (null: a root) or the same one (a new order), at a position among the new siblings: 0 is the
 * first, a position past the last sibling the end.
 */
final readonly class MoveCategory
{
    public function __construct(
        public string $categoryId,
        public int $expectedVersion,
        public ?string $parentId,
        public int $position,
    ) {
    }
}

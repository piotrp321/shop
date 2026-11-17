<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * A new category, at the end of its siblings. The seed creates its categories at position 0 without renumbering the
 * siblings and without CategoryTreeChanged (bySeed), so the demo tree keeps the alphabetical order and the seed ends
 * with one purge of the store's cache instead of one per category.
 */
final readonly class CreateCategory
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $parentId,
        public bool $bySeed = false,
    ) {
    }

    public static function bySeed(string $id, string $name, string $slug, ?string $parentId): self
    {
        return new self($id, $name, $slug, $parentId, true);
    }
}

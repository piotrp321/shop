<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\SubtreeProductIds;
use App\Catalog\Domain\Category\CategoryId;

/**
 * The product ids of each category subtree from a map; throws the queued failure instead of answering.
 */
final class InMemorySubtreeProductIds implements SubtreeProductIds
{
    public ?\Throwable $failure = null;

    /**
     * @param array<string, list<string>> $subtrees category id => product ids
     */
    public function __construct(private array $subtrees = [])
    {
    }

    public function after(CategoryId $categoryId, ?string $afterId, int $limit): array
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $ids = $this->subtrees[$categoryId->toString()] ?? [];
        sort($ids);

        return \array_slice(array_values(array_filter($ids, static fn (string $id): bool => null === $afterId || $id > $afterId)), 0, $limit);
    }
}

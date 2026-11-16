<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * The state of a run of the reindexing of a category subtree, read under the lock of its row: the last product id of
 * the batches done (null before the first), the number of the batch it expects next, and whether it has finished.
 */
final readonly class CategoryReindexRun
{
    public function __construct(
        public string $id,
        public string $categoryId,
        public ?string $cursor,
        public int $nextBatch,
        public bool $finished,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * One batch of the chain that reindexes the products of a category subtree after a rename or a move (spec
 * ADMIN-FEATURES, 4 "Odświeżenie sklepu i reindeksacja"). Routed to the Doctrine transport `jobs`; sent in the
 * transaction that started the run or handled the previous batch. $batch is the number the run expects next; a copy
 * with another number, or one of a finished run, is handled without effect.
 */
final readonly class ReindexCategoryBatch
{
    public function __construct(
        public string $runId,
        public int $batch,
    ) {
    }
}

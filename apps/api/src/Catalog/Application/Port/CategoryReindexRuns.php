<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Command\CategoryReindexRun;
use App\Catalog\Domain\Category\CategoryId;

/**
 * The runs of the reindexing of category subtrees (catalog_category_reindex). Every method writes or locks in the
 * caller's transaction (command.bus).
 */
interface CategoryReindexRuns
{
    /**
     * A new run of the subtree, without a cursor, expecting batch 0.
     *
     * @return string the id of the run
     */
    public function start(CategoryId $categoryId): string;

    /**
     * The run, its row locked until the end of the transaction, so concurrent deliveries of a batch go one after
     * another; null when there is no such run (the demo seed empties the table).
     *
     * @throws \LogicException outside a transaction, where the lock would guard nothing
     */
    public function lock(string $runId): ?CategoryReindexRun;

    /**
     * The batch is done: the last product id it reindexed and the number of the batch that comes next.
     */
    public function advance(string $runId, string $cursor, int $nextBatch): void;

    public function finish(string $runId, \DateTimeImmutable $at): void;
}

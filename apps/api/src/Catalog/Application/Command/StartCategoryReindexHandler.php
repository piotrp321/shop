<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Port\CategoryReindexBatches;
use App\Catalog\Application\Port\CategoryReindexRuns;
use App\Catalog\Domain\Category\CategoryId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes a new run of the reindexing of a category subtree and sends its first batch, in one transaction of
 * command.bus: the run and its message are committed together or not at all.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class StartCategoryReindexHandler
{
    public function __construct(
        private CategoryReindexRuns $runs,
        private CategoryReindexBatches $batches,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the category id is not a UUID
     */
    public function __invoke(StartCategoryReindex $command): void
    {
        $runId = $this->runs->start(CategoryId::fromString($command->categoryId));
        $this->batches->send($runId, 0);
    }
}

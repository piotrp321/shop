<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\AsyncProductSearchIndexRebuild;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exit codes: 0 the finalization was sent again (worker-indexing handles it from the saved stage), 1 no run is in its
 * finalization.
 */
#[AsCommand(name: 'catalog:reindex:resume', description: 'Sends the finalization of the active asynchronous rebuild run again from its saved stage, e.g. after its message was lost.')]
final readonly class ResumeReindexCommand
{
    public function __construct(
        private AsyncProductSearchIndexRebuild $asyncRebuild,
        private SearchRebuildRuns $runs,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $run = $this->asyncRebuild->resume();
        if (null !== $run) {
            $io->success(\sprintf('The finalization of the run %s was sent again from its stage %s.', $run->id, $run->status->value));

            return Command::SUCCESS;
        }
        $active = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        $io->error(null === $active
            ? 'No rebuild run is active.'
            : \sprintf('The run %s is %s: it is not in its finalization (catalog:reindex:abort ends it).', $active->id, $active->status->value));

        return Command::FAILURE;
    }
}

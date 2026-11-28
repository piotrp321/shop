<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Search\AsyncProductSearchIndexRebuild;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exit codes: 0 the run was aborted (a warning when its index could not be deleted), 1 nothing was aborted.
 */
#[AsCommand(name: 'catalog:reindex:abort', description: 'Aborts the active asynchronous rebuild run of the search index while it takes batches and deletes its new index.')]
final readonly class AbortReindexCommand
{
    public function __construct(
        private AsyncProductSearchIndexRebuild $asyncRebuild,
        #[Autowire('%app.search.rebuild_stall_seconds%')]
        private int $stallSeconds = 1800,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Abort also a run that is alive (it moved within the stall limit)')]
        bool $force = false,
    ): int {
        $outcome = $this->asyncRebuild->abort($force, $this->stallSeconds);
        foreach ($outcome->warnings as $warning) {
            $io->warning($warning);
        }
        if (!$outcome->aborted) {
            $io->error($outcome->message);

            return Command::FAILURE;
        }
        $io->success($outcome->message);

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'catalog:search-index:setup', description: 'Creates the product search alias and index if missing and checks their state.')]
final readonly class SetupSearchIndexCommand
{
    public function __construct(
        private SearchIndexMaintenance $maintenance,
        private SearchIndexRebuildLock $lock,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Delete the alias and all its indexes, then create them again (refused in prod)')]
        bool $recreate = false,
    ): int {
        if ($recreate && 'prod' === $this->environment) {
            $io->error('--recreate deletes the search index and is refused in prod. Use catalog:reindex to rebuild it.');

            return Command::FAILURE;
        }

        if (!$this->lock->tryAcquire()) {
            $io->error('A rebuild or setup of the search index is running. Try again when it has finished.');

            return Command::FAILURE;
        }

        try {
            if ($recreate) {
                $this->maintenance->recreate();
                $io->success('The search index was recreated.');

                return Command::SUCCESS;
            }

            $orphans = $this->maintenance->setup();
        } catch (SearchIndexError|SearchUnavailable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->lock->release();
        }

        if ([] !== $orphans) {
            $io->warning(\sprintf(
                "Indexes the alias does not point to (left in place; delete them when no longer needed):\n%s",
                implode("\n", $orphans),
            ));
        }
        $io->success('The search index is set up.');

        return Command::SUCCESS;
    }
}

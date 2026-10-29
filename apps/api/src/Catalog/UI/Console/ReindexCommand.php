<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exit codes: 0 the alias points to the new index, 1 the alias stays on the previous index, 2 the alias state
 * is unknown (both indexes are kept).
 */
#[AsCommand(name: 'catalog:reindex', description: 'Rebuilds the product search index from the database and switches the alias to it.')]
final readonly class ReindexCommand
{
    public const int UNKNOWN = 2;

    public function __construct(
        private ProductSearchIndexRebuilder $rebuilder,
        #[Autowire('%app.search.batch_size%')]
        private int $defaultBatchSize,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Products per bulk request (default from the app.search.batch_size parameter)')]
        ?int $batchSize = null,
    ): int {
        $batchSize ??= $this->defaultBatchSize;
        if ($batchSize < 1) {
            $io->error('--batch-size must be at least 1.');

            return Command::FAILURE;
        }

        $report = $this->rebuilder->rebuild($batchSize);
        foreach ($report->warnings as $warning) {
            $io->warning($warning);
        }

        switch ($report->outcome) {
            case ActivationResult::Activated:
                $io->success(\sprintf('The alias now points to "%s" (%d documents); "%s" was replaced.', $report->newIndex, $report->documents, $report->previousIndex));

                return Command::SUCCESS;
            case ActivationResult::NotActivated:
                $io->error(\sprintf('The search index was not rebuilt: %s', $report->failure));

                return Command::FAILURE;
            case ActivationResult::Unknown:
                $io->error((string) $report->failure);

                return self::UNKNOWN;
        }
    }
}

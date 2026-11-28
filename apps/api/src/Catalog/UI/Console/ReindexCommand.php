<?php

declare(strict_types=1);

namespace App\Catalog\UI\Console;

use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\AsyncProductSearchIndexRebuild;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchRebuildRun;
use App\Catalog\Application\Search\SearchRebuildStatus;
use App\Catalog\Application\Search\SearchUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Without --async the rebuild runs here, synchronously. With --async (docs/features/async-indexing/spec.md) the command
 * starts a run, sends its batches to the workers of worker-indexing and ends; with --wait it follows the run to its end.
 *
 * Exit codes: 0 the alias points to the new index (with --async without --wait: all batches were sent), 1 the alias
 * stays on the previous index (also a refusal, and a run that stopped moving for longer than the stall limit while the
 * alias is on the previous index), 2 the alias state is unknown (both indexes are kept).
 */
#[AsCommand(name: 'catalog:reindex', description: 'Rebuilds the product search index from the database and the product offers and switches the alias to it.')]
final readonly class ReindexCommand
{
    public const int UNKNOWN = 2;

    public function __construct(
        private ProductSearchIndexRebuilder $rebuilder,
        private AsyncProductSearchIndexRebuild $asyncRebuild,
        private SearchRebuildRuns $runs,
        private SearchIndexMaintenance $maintenance,
        private Clock $clock,
        #[Autowire('%app.search.indexing_batch_size%')]
        private int $defaultBatchSize,
        #[Autowire('%app.search.rebuild_stall_seconds%')]
        private int $stallSeconds = 1800,
        #[Autowire('%app.search.rebuild_poll_seconds%')]
        private float $pollSeconds = 5.0,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Products per batch: one bulk request, with --async one message (default SEARCH_INDEX_BATCH_SIZE, 1 to 5000)')]
        ?int $batchSize = null,
        #[Option(description: 'Start a run on the workers of worker-indexing instead of rebuilding here')]
        bool $async = false,
        #[Option(description: 'With --async: follow the run to its end and print its progress')]
        bool $wait = false,
    ): int {
        if (null === $batchSize && ($this->defaultBatchSize < 1 || $this->defaultBatchSize > AsyncProductSearchIndexRebuild::MAX_BATCH_SIZE)) {
            $io->error(\sprintf('SEARCH_INDEX_BATCH_SIZE must be from 1 to %d, it is %d.', AsyncProductSearchIndexRebuild::MAX_BATCH_SIZE, $this->defaultBatchSize));

            return Command::FAILURE;
        }
        $batchSize ??= $this->defaultBatchSize;
        if ($batchSize < 1 || $batchSize > AsyncProductSearchIndexRebuild::MAX_BATCH_SIZE) {
            $io->error(\sprintf('--batch-size must be from 1 to %d.', AsyncProductSearchIndexRebuild::MAX_BATCH_SIZE));

            return Command::FAILURE;
        }
        if ($wait && !$async) {
            $io->error('--wait needs --async.');

            return Command::FAILURE;
        }

        return $async ? $this->async($io, $batchSize, $wait) : $this->sync($io, $batchSize);
    }

    private function sync(SymfonyStyle $io, int $batchSize): int
    {
        $report = $this->rebuilder->rebuild($batchSize);
        foreach ($report->warnings as $warning) {
            $io->warning($warning);
        }

        switch ($report->outcome) {
            case ActivationResult::Activated:
                $io->success(\sprintf('The alias now points to "%s" (%d documents); "%s" was replaced.', $report->newIndex, $report->documents, $report->previousIndex));

                return Command::SUCCESS;
            case ActivationResult::NotActivated:
                $active = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
                $io->error(\sprintf('The search index was not rebuilt: %s', null === $active ? $report->failure : \sprintf('the rebuild run %s is %s.', $active->id, $active->status->value)));

                return Command::FAILURE;
            case ActivationResult::Unknown:
                $io->error((string) $report->failure);

                return self::UNKNOWN;
        }
    }

    private function async(SymfonyStyle $io, int $batchSize, bool $wait): int
    {
        $start = $this->asyncRebuild->start($batchSize);
        if (null !== $start->failure) {
            $io->error(\sprintf('The search index was not rebuilt: %s', $start->failure));

            return Command::FAILURE;
        }
        \assert(null !== $start->runId);
        $io->writeln(\sprintf('Run %s: all batches sent (%d products per batch).', $start->runId, $batchSize));
        if (!$wait) {
            return Command::SUCCESS;
        }

        return $this->follow($io, $start->runId);
    }

    private function follow(SymfonyStyle $io, string $runId): int
    {
        $shown = null;
        while (true) {
            $run = $this->runs->get($runId);
            \assert(null !== $run);
            $line = \sprintf('%s: %d/%s batches, %d documents', $run->status->value, $run->doneBatches, $run->totalBatches ?? '?', $run->documents);
            if ($line !== $shown) {
                $elapsed = $this->clock->now()->getTimestamp() - $run->startedAt->getTimestamp();
                $io->writeln($line.($elapsed > 0 ? \sprintf(', %.1f documents/s', $run->documents / $elapsed) : ''));
                $shown = $line;
            }
            if (SearchRebuildStatus::Succeeded === $run->status) {
                $io->success(\sprintf('The alias now points to "%s" (%d documents); "%s" was replaced.', $run->targetIndex, $run->documents, $run->previousIndex));

                return Command::SUCCESS;
            }
            if ($run->status->isFinal()) {
                $io->error(\sprintf('The run %s ended %s: %s', $run->id, $run->status->value, $run->failure ?? 'no reason saved'));

                return $this->aliasState($io, $run);
            }
            if ($run->isStalled($this->clock->now(), $this->stallSeconds)) {
                $io->error(\sprintf('The run %s has not moved for more than %d s (is worker-indexing running? a message in failed?). It goes on when it moves again; catalog:reindex:abort ends it.', $run->id, $this->stallSeconds));

                return $this->aliasState($io, $run);
            }
            usleep((int) ($this->pollSeconds * 1_000_000));
        }
    }

    private function aliasState(SymfonyStyle $io, SearchRebuildRun $run): int
    {
        try {
            $active = $this->maintenance->activeIndex();
        } catch (SearchUnavailable|SearchIndexError) {
            $active = null;
        }
        if ($active === $run->previousIndex) {
            return Command::FAILURE;
        }
        $io->error(\sprintf('The alias state is unknown: it points to %s (previous "%s", new "%s").', null === $active ? 'an index that cannot be read' : '"'.$active.'"', $run->previousIndex, $run->targetIndex));

        return self::UNKNOWN;
    }
}

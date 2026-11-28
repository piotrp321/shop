<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search\AsyncRebuild;

use App\Catalog\Application\Command\FinalizeSearchRebuild;
use App\Catalog\Application\Command\FinalizeSearchRebuildHandler;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\ForeignRebuildBatch;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchRebuildRun;
use App\Catalog\Application\Search\SearchRebuildStatus;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Tests\Support\InMemory\FixedCatalogClock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The register of the runs (docs/features/async-indexing/spec.md, "Rozliczenie paczki", "Producent kończy…") and the
 * finalization with its resumption and two handlers of one run, on Postgres; Elasticsearch is a fake with the state
 * of the alias. In the `test` kernel the transport indexing_finalize is in memory: its messages are counted there.
 */
final class SearchRebuildRunsTest extends KernelTestCase
{
    private SearchRebuildRuns $runs;
    private InMemoryTransport $finalizations;
    private FixedCatalogClock $clock;
    private Connection $connection;

    protected function setUp(): void
    {
        $runs = self::getContainer()->get(SearchRebuildRuns::class);
        \assert($runs instanceof SearchRebuildRuns);
        $this->runs = $runs;
        $transport = self::getContainer()->get('messenger.transport.indexing_finalize');
        \assert($transport instanceof InMemoryTransport);
        $this->finalizations = $transport;
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM catalog_search_rebuild_run');
        $this->clock = new FixedCatalogClock(new \DateTimeImmutable('2026-11-28T12:00:00+00:00'));
    }

    public function testARepeatedBatchCountsOnceAndTheLastBatchFinalizesOnce(): void
    {
        $run = $this->start();
        self::assertTrue($this->runs->finishProduction($run, 2, $this->clock->now()));

        self::assertSame(3, $this->countBatch($run, 0, 3)?->documents);
        self::assertNull($this->countBatch($run, 0, 3), 'a second delivery of batch 0');
        self::assertSame([], $this->finalizations->getSent());
        $last = $this->countBatch($run, 1, 2);

        self::assertSame(SearchRebuildStatus::Finalizing, $last?->status);
        self::assertSame([2, 5], [$last->doneBatches, $last->documents]);
        self::assertNull($this->countBatch($run, 1, 2), 'a copy of the last batch');
        self::assertSame([$run], $this->finalizationsSent());
    }

    public function testBatchesCountedBeforeTheProducerFinishesAreFinalizedByTheProducer(): void
    {
        $run = $this->start();
        $this->countBatch($run, 1, 2);
        $this->countBatch($run, 0, 3);
        self::assertSame([], $this->finalizations->getSent(), 'the total is not known yet');

        self::assertTrue($this->runs->finishProduction($run, 2, $this->clock->now()));

        self::assertSame(SearchRebuildStatus::Finalizing, $this->runs->get($run)?->status);
        self::assertSame([$run], $this->finalizationsSent());
    }

    public function testAnEmptyCatalogIsFinalizedByTheProducerAtOnce(): void
    {
        $run = $this->start();

        self::assertTrue($this->runs->finishProduction($run, 0, $this->clock->now()));

        self::assertSame(SearchRebuildStatus::Finalizing, $this->runs->get($run)?->status);
        self::assertSame([$run], $this->finalizationsSent());
    }

    public function testARunThatNoLongerTakesBatchesCountsNothing(): void
    {
        $run = $this->start();
        self::assertTrue($this->runs->move($run, [SearchRebuildStatus::Producing], SearchRebuildStatus::Aborted, $this->clock->now()));

        self::assertNull($this->countBatch($run, 0, 3));
        self::assertFalse($this->runs->finishProduction($run, 1, $this->clock->now()));
        self::assertSame(0, $this->runs->get($run)?->doneBatches);
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM catalog_search_rebuild_batch WHERE run_id = ? AND documents IS NOT NULL', [$run]));
        self::assertSame([], $this->finalizations->getSent());
    }

    public function testOnlyABatchTheProducerRegisteredIsCountedAndOnlyWithItsIds(): void
    {
        $run = $this->start();

        foreach ([[0, [self::ids(0)[0]]], [0, self::ids(1)], [2, self::ids(0)], [0, array_reverse(self::ids(0))]] as [$batch, $ids]) {
            try {
                $this->runs->countBatch($run, $batch, $ids, 1, $this->clock->now());
                self::fail('Expected a foreign batch to be refused.');
            } catch (ForeignRebuildBatch) {
            }
        }
        self::assertSame([0, 0], [$this->runs->get($run)?->doneBatches, $this->runs->get($run)?->documents], 'nothing counted, the transaction rolled back');

        self::assertSame(1, $this->countBatch($run, 0, 2)?->doneBatches);
        self::assertNull($this->countBatch($run, 0, 2), 'its copy');
        self::assertTrue($this->runs->move($run, [SearchRebuildStatus::Producing], SearchRebuildStatus::Aborted, $this->clock->now()));
        self::assertNull($this->runs->countBatch($run, 9, ['x'], 1, $this->clock->now()), 'a run that takes no batches refuses nothing: it counts nothing');
    }

    public function testTheFinalizationIsSentAgainOnlyFromAStageOfTheFinalization(): void
    {
        $run = $this->finalizingRun();
        $sent = \count($this->finalizationsSent());
        $this->connection->executeStatement("UPDATE catalog_search_rebuild_run SET status = 'running' WHERE id = ?", [$run]);
        self::assertFalse($this->runs->resumeFinalization($run, $this->clock->now()), 'running');

        foreach ([SearchRebuildStatus::Finalizing, SearchRebuildStatus::Verified, SearchRebuildStatus::Activating, SearchRebuildStatus::Activated] as $status) {
            $this->connection->executeStatement('UPDATE catalog_search_rebuild_run SET status = ? WHERE id = ?', [$status->value, $run]);
            self::assertTrue($this->runs->resumeFinalization($run, $this->clock->now()), $status->value);
        }
        self::assertCount($sent + 4, $this->finalizationsSent());

        $this->connection->executeStatement("UPDATE catalog_search_rebuild_run SET status = 'succeeded', finished_at = now() WHERE id = ?", [$run]);
        self::assertFalse($this->runs->resumeFinalization($run, $this->clock->now()), 'final');
    }

    public function testAMoveIsConditionalOnTheStatusSeenAndAFinalStatusIsNeverOverwritten(): void
    {
        $run = $this->start();
        $this->runs->move($run, [SearchRebuildStatus::Producing], SearchRebuildStatus::Failed, $this->clock->now(), 'first');

        self::assertFalse($this->runs->move($run, [SearchRebuildStatus::Producing], SearchRebuildStatus::Running, $this->clock->now()));
        self::assertFalse($this->runs->move($run, [SearchRebuildStatus::Producing, SearchRebuildStatus::Running], SearchRebuildStatus::Aborted, $this->clock->now(), 'second'));
        $saved = $this->runs->get($run);
        self::assertNotNull($saved);
        self::assertSame([SearchRebuildStatus::Failed, 'first'], [$saved->status, $saved->failure]);
        self::assertNotNull($saved->finishedAt);
    }

    public function testOneActiveRunPerDocumentTypeAndTheLockRefusesWhileOneIsActive(): void
    {
        $lock = self::getContainer()->get(SearchIndexRebuildLock::class);
        \assert($lock instanceof SearchIndexRebuildLock);
        $run = $this->start();

        self::assertFalse($lock->tryAcquire(), 'a synchronous rebuild, setup or reset');
        self::assertFalse($lock->tryShareInTransaction(), 'the creation of a product');
        try {
            $this->start();
            self::fail('A second active run of the type.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        }
        $this->connection->executeStatement('ROLLBACK TO SAVEPOINT before_second');

        $this->runs->move($run, [SearchRebuildStatus::Producing], SearchRebuildStatus::Aborted, $this->clock->now());
        self::assertTrue($lock->tryAcquire());
        $lock->release();
        self::assertTrue($lock->tryShareInTransaction());
    }

    public function testTheFinalizationVerifiesSwitchesTheAliasOnceAndDeletesThePreviousIndex(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $run = $this->finalizingRun();

        $this->handler($es)(new FinalizeSearchRebuild($run));
        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run)?->status);
        self::assertSame(['test_products_target', 1, ['test_products_old']], [$es->alias, $es->activations, $es->deleted]);
    }

    /**
     * @return iterable<string, array{SearchRebuildStatus, string}>
     */
    public static function interruptedStages(): iterable
    {
        yield 'finalizing' => [SearchRebuildStatus::Finalizing, 'test_products_old'];
        yield 'verified' => [SearchRebuildStatus::Verified, 'test_products_old'];
        yield 'activating, the alias still on the previous index' => [SearchRebuildStatus::Activating, 'test_products_old'];
        yield 'activating, the switch reached the index before the crash' => [SearchRebuildStatus::Activating, 'test_products_target'];
        yield 'activated' => [SearchRebuildStatus::Activated, 'test_products_target'];
    }

    #[DataProvider('interruptedStages')]
    public function testAFinalizationInterruptedAtAnyStageResumesToSucceededWithOneSwitchAtMost(SearchRebuildStatus $stage, string $alias): void
    {
        $es = new AliasFakeMaintenance($alias, 'test_products_old', 'test_products_target');
        $run = $this->finalizingRun();
        $this->connection->executeStatement('UPDATE catalog_search_rebuild_run SET status = ? WHERE id = ?', [$stage->value, $run]);

        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run)?->status);
        self::assertSame('test_products_target', $es->alias);
        self::assertSame('test_products_old' === $alias ? 1 : 0, $es->activations);
        self::assertSame(['test_products_old'], $es->deleted);
    }

    public function testASecondHandlerThatRunsWhileTheFirstVerifiesNeverSwitchesTwiceNorOverwritesTheEnd(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $run = $this->finalizingRun();
        // The first handler is paused in its verify; the second (a redelivery) does the whole finalization meanwhile.
        $es->duringVerify = fn () => $this->handler($es)(new FinalizeSearchRebuild($run));

        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run)?->status);
        self::assertSame(['test_products_target', 1, ['test_products_old']], [$es->alias, $es->activations, $es->deleted]);
    }

    public function testASecondHandlerThatSwitchesWhileTheFirstSwitchesLeavesOneSwitch(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $run = $this->finalizingRun();
        $this->connection->executeStatement("UPDATE catalog_search_rebuild_run SET status = 'activating' WHERE id = ?", [$run]);
        // Both read the alias on the previous index; the second switches first, so the first one's switch is refused.
        $es->duringActivate = fn () => $this->handler($es)(new FinalizeSearchRebuild($run));

        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run)?->status);
        self::assertSame(['test_products_target', 1, ['test_products_old']], [$es->alias, $es->activations, $es->deleted]);
    }

    public function testALostAnswerOfTheSwitchIsDecidedByTheAliasRead(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $es->loseActivateAnswer = true;
        $run = $this->finalizingRun();

        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run)?->status);
    }

    public function testARejectedSwitchIsRetriedFromActivatingAndKeepsTheNewIndex(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $es->rejectSwitches = 1;
        $run = $this->finalizingRun();

        try {
            $this->handler($es)(new FinalizeSearchRebuild($run));
            self::fail('Expected a transient failure, so the transport retries the stage.');
        } catch (SearchUnavailable) {
        }
        self::assertSame(SearchRebuildStatus::Activating, $this->runs->get($run)?->status);
        self::assertSame([], $es->deleted);

        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run)?->status);
        self::assertSame(['test_products_target', 1], [$es->alias, $es->activations]);
    }

    public function testTheBeatOfTheProducerSaysWhetherTheRunStillTakesItsBatches(): void
    {
        $run = $this->start();

        self::assertTrue($this->runs->beat($run, $this->clock->now()));
        $this->runs->move($run, [SearchRebuildStatus::Producing], SearchRebuildStatus::Aborted, $this->clock->now());
        self::assertFalse($this->runs->beat($run, $this->clock->now()));
    }

    public function testAFailedVerifyFailsTheRunAndDeletesOnlyTheNewIndex(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $es->verifyFailure = new SearchIndexError('The index holds 4 documents, 5 were written.');
        $run = $this->finalizingRun();

        $this->handler($es)(new FinalizeSearchRebuild($run));

        $saved = $this->runs->get($run);
        self::assertNotNull($saved);
        self::assertSame([SearchRebuildStatus::Failed, 'The index holds 4 documents, 5 were written.'], [$saved->status, $saved->failure]);
        self::assertSame(['test_products_old', 0, ['test_products_target']], [$es->alias, $es->activations, $es->deleted]);
    }

    public function testAnUnreachableIndexIsRetriedFromTheSameStage(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $es->verifyFailure = new SearchUnavailable('timeout');
        $run = $this->finalizingRun();

        try {
            $this->handler($es)(new FinalizeSearchRebuild($run));
            self::fail('Expected the transient failure to be thrown for the retry.');
        } catch (SearchUnavailable) {
        }

        self::assertSame(SearchRebuildStatus::Finalizing, $this->runs->get($run)?->status);
        self::assertSame([], $es->deleted);
    }

    public function testTheAliasOnAThirdIndexFailsTheRunAndKeepsBothIndexes(): void
    {
        $es = new AliasFakeMaintenance('test_products_other', 'test_products_old', 'test_products_target');
        $run = $this->finalizingRun();
        $this->connection->executeStatement("UPDATE catalog_search_rebuild_run SET status = 'activating' WHERE id = ?", [$run]);

        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Failed, $this->runs->get($run)?->status);
        self::assertSame([0, []], [$es->activations, $es->deleted]);
    }

    public function testAFailedDeletionOfThePreviousIndexIsAWarningAndTheRunSucceeds(): void
    {
        $es = new AliasFakeMaintenance('test_products_old', 'test_products_target');
        $es->duringDelete = static function (): void {
            throw new SearchUnavailable('timeout');
        };
        $run = $this->finalizingRun();

        $this->handler($es)(new FinalizeSearchRebuild($run));

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run)?->status);
    }

    private function start(): string
    {
        $this->connection->executeStatement('SAVEPOINT before_second');
        $run = $this->runs->start('product', 'test_products_target', 'test_products_old', 500, $this->clock->now());
        // The producer registers every batch before it sends it.
        foreach ([0, 1] as $batch) {
            $this->runs->registerBatch($run, $batch, self::ids($batch));
        }

        return $run;
    }

    private function countBatch(string $run, int $batch, int $documents): ?SearchRebuildRun
    {
        return $this->runs->countBatch($run, $batch, self::ids($batch), $documents, $this->clock->now());
    }

    /**
     * @return list<string>
     */
    private static function ids(int $batch): array
    {
        return [\sprintf('0199%04d-0000-7000-8000-000000000000', $batch), \sprintf('0199%04d-0000-7000-8000-000000000001', $batch)];
    }

    private function finalizingRun(): string
    {
        $run = $this->start();
        // As many documents as the catalog has, so the check against the catalog passes.
        $this->countBatch($run, 0, $this->source()->count());
        $this->runs->finishProduction($run, 1, $this->clock->now());
        self::assertSame(SearchRebuildStatus::Finalizing, $this->runs->get($run)?->status);

        return $run;
    }

    private function handler(AliasFakeMaintenance $es): FinalizeSearchRebuildHandler
    {
        return new FinalizeSearchRebuildHandler($this->runs, $es, $this->source(), $this->clock);
    }

    private function source(): ProductSearchSource
    {
        $source = self::getContainer()->get(ProductSearchSource::class);
        \assert($source instanceof ProductSearchSource);

        return $source;
    }

    /**
     * @return list<string>
     */
    private function finalizationsSent(): array
    {
        return array_values(array_map(static function ($envelope): string {
            $message = $envelope->getMessage();
            \assert($message instanceof FinalizeSearchRebuild);

            return $message->runId;
        }, [...$this->finalizations->getSent()]));
    }
}

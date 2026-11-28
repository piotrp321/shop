<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search\AsyncRebuild;

use App\Cart\Infrastructure\Catalog\QueryBusProductOfferFacts;
use App\Catalog\Application\Command\FinalizeSearchRebuild;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\AsyncProductSearchIndexRebuild;
use App\Catalog\Application\Search\ForeignRebuildBatch;
use App\Catalog\Application\Search\ProductBatchIndexer;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductRebuildBatch;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchRebuildStatus;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Shared\Infrastructure\Indexing\IndexDocuments;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\InMemory\FixedCatalogClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * catalog:reindex --async on the real test_products alias (criteria 1, 2, 6, 8 of ASYNC-INDEXING): the batches and the
 * finalization wait in the in-memory transports of the `test` kernel and are handled here as a worker does (ReceivedStamp
 * on indexing.bus). Products in shop_test are rolled back after each test, the alias is recreated.
 */
final class AsyncSearchIndexRebuildTest extends KernelTestCase
{
    use Factories;

    private SearchIndexMaintenance $maintenance;
    private SearchRebuildRuns $runs;

    protected function setUp(): void
    {
        $maintenance = self::getContainer()->get(SearchIndexMaintenance::class);
        \assert($maintenance instanceof SearchIndexMaintenance);
        $this->maintenance = $maintenance;
        $runs = self::getContainer()->get(SearchRebuildRuns::class);
        \assert($runs instanceof SearchRebuildRuns);
        $this->runs = $runs;
        $this->maintenance->recreate();
    }

    protected function tearDown(): void
    {
        $this->maintenance->recreate();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function catalogSizes(): iterable
    {
        yield 'empty catalog' => [0, 2, 0];
        yield 'one product' => [1, 2, 1];
        yield 'size minus one' => [3, 4, 1];
        yield 'size' => [4, 4, 1];
        yield 'size plus one' => [5, 4, 2];
        yield 'a multiple' => [6, 2, 3];
    }

    #[DataProvider('catalogSizes')]
    public function testTheBatchesOfTheWorkersRebuildTheIndexAndTheFinalizationSwitchesTheAlias(int $products, int $batchSize, int $messages): void
    {
        $ids = $this->createProducts($products);
        $previous = $this->maintenance->activeIndex();

        $tester = $this->command('catalog:reindex');
        self::assertSame(Command::SUCCESS, $tester->execute(['--async' => true, '--batch-size' => (string) $batchSize]), $tester->getDisplay());

        self::assertCount($messages, $this->transport('indexing')->getSent());
        $this->work();

        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNull($run, 'The run ended.');
        $target = $this->maintenance->activeIndex();
        self::assertNotSame($previous, $target);
        self::assertFalse($this->indexExists($previous), 'The previous index was deleted.');
        self::assertSame($ids, $this->indexedIds());
    }

    public function testACopyOfACountedBatchWrittenAfterTheSwitchChangesNothing(): void
    {
        $ids = $this->createProducts(3);
        $tester = $this->command('catalog:reindex');
        $tester->execute(['--async' => true, '--batch-size' => '2']);
        $copy = $this->transport('indexing')->getSent()[0];

        $this->work();
        $this->handle($copy);

        self::assertSame($ids, $this->indexedIds());
    }

    public function testAMalformedBatchGoesToFailedWithoutAWrite(): void
    {
        $this->createProducts(1);
        $this->command('catalog:reindex')->execute(['--async' => true]);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);

        foreach ([[], ["x' OR 1=1"], array_fill(0, 5001, '00000000-0000-4000-8000-000000000001')] as $ids) {
            try {
                $this->handle(new Envelope(new IndexDocuments('product', $ids, $run->id, 0)));
                self::fail('Expected a permanent failure.');
            } catch (HandlerFailedException $e) {
                self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $e->getPrevious());
            }
        }
        self::assertSame(0, $this->runs->get($run->id)?->doneBatches);
        $this->command('catalog:reindex:abort')->execute(['--force' => true]);
    }

    public function testWaitWithoutAWorkerGivesUpAfterTheStallLimitAndAbortEndsTheRun(): void
    {
        $this->createProducts(2);
        $previous = $this->maintenance->activeIndex();
        $wait = $this->command('catalog:reindex');

        $started = microtime(true);
        self::assertSame(Command::FAILURE, $wait->execute(['--async' => true, '--wait' => true]), $wait->getDisplay());
        self::assertLessThan(30, microtime(true) - $started);
        self::assertStringContainsString('has not moved', $wait->getDisplay());

        $refused = $this->command('catalog:reindex');
        self::assertSame(Command::FAILURE, $refused->execute([]), 'a synchronous rebuild while the run is active');
        self::assertStringContainsString('is running', $refused->getDisplay());

        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        $abort = $this->command('catalog:reindex:abort');
        self::assertSame(Command::SUCCESS, $abort->execute([]), $abort->getDisplay());
        self::assertSame(SearchRebuildStatus::Aborted, $this->runs->get($run->id)?->status);
        self::assertFalse($this->indexExists($run->targetIndex));
        self::assertSame($previous, $this->maintenance->activeIndex());

        // Its batches end without a write.
        $this->work();
        self::assertFalse($this->indexExists($run->targetIndex));
        self::assertSame(Command::FAILURE, $abort->execute([]), 'nothing left to abort');
    }

    public function testAbortRefusesALiveRunWithoutForceAndAFinalizingRun(): void
    {
        $this->createProducts(1);
        $this->command('catalog:reindex')->execute(['--async' => true]);
        $abort = $this->command('catalog:reindex:abort');

        self::assertSame(Command::FAILURE, $abort->execute([]), $abort->getDisplay());
        self::assertStringContainsString('--force', $abort->getDisplay());

        $this->handle($this->transport('indexing')->getSent()[0]);
        self::assertSame(Command::FAILURE, $abort->execute(['--force' => true]), 'finalizing');
        self::assertStringContainsString('finalization', $abort->getDisplay());
        $this->work();
    }

    public function testABatchThatIsNotOneTheProducerSentIsRefusedAndTheRunStillIndexesTheWholeCatalog(): void
    {
        $ids = $this->createProducts(3);
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '2']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);

        // A valid UUID of no product in the place of batch 0, and a real product under a number the producer never used.
        foreach ([[['01990000-0000-7000-8000-000000000001'], 0], [[$ids[0]], 7]] as [$forged, $batch]) {
            try {
                $this->handle(new Envelope(new IndexDocuments('product', $forged, $run->id, $batch)));
                self::fail('Expected a permanent failure of a batch that is not of the run.');
            } catch (HandlerFailedException $e) {
                self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $e->getPrevious());
            }
        }
        self::assertSame(0, $this->runs->get($run->id)?->doneBatches);

        $this->work();

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run->id)?->status);
        self::assertSame($ids, $this->indexedIds());
    }

    public function testABatchThatIsNotOneTheProducerSentWritesNothingToTheIndex(): void
    {
        $ids = $this->createProducts(3);
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '2']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        $real = $this->transport('indexing')->getSent()[0]->getMessage();
        \assert($real instanceof IndexDocuments);

        // A valid UUID of no product, a real product under a number never sent, and batch 0 with one id of it left out.
        foreach ([[['01990000-0000-7000-8000-000000000001'], 0], [[$ids[2]], 7], [[$real->ids[0]], 0]] as [$forged, $batch]) {
            $es = new HookedMaintenance($this->maintenance);
            $es->beforeWrite = static function (): void {
                self::fail('A batch the producer never sent reached the bulk write.');
            };
            try {
                $this->batchOf($es, new Envelope(new IndexDocuments('product', $forged, $run->id, $batch)));
                self::fail('Expected the batch to be refused.');
            } catch (ForeignRebuildBatch) {
            }
            self::assertNotNull($es->beforeWrite, 'no write was attempted');
        }
        self::assertSame(0, $this->runs->get($run->id)?->doneBatches);
        $this->command('catalog:reindex:abort')->execute(['--force' => true]);
    }

    public function testAProductThatTheBatchesMissedFailsTheVerificationAgainstTheCatalogAndTheAliasStays(): void
    {
        $this->createProducts(2);
        $previous = $this->maintenance->activeIndex();
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '2']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        // Saved past the lock of the rebuild (the repository, not the command), so no batch has it.
        $this->createProducts(1);

        $this->work();

        $ended = $this->runs->get($run->id);
        self::assertSame(SearchRebuildStatus::Failed, $ended?->status);
        self::assertStringContainsString('3', (string) $ended->failure);
        self::assertSame($previous, $this->maintenance->activeIndex());
        self::assertFalse($this->indexExists($run->targetIndex));
    }

    public function testALostFinalizationIsResumedFromTheSavedStage(): void
    {
        $ids = $this->createProducts(3);
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '2']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        $resume = $this->command('catalog:reindex:resume');
        self::assertSame(Command::FAILURE, $resume->execute([]), 'a run that takes batches is not resumed');

        while ([] !== ($envelopes = $this->drain('indexing'))) {
            foreach ($envelopes as $envelope) {
                $this->handle($envelope);
            }
        }
        self::assertCount(1, $this->drain('indexing_finalize'), 'the finalization is lost');
        self::assertSame(SearchRebuildStatus::Finalizing, $this->runs->get($run->id)?->status);

        $resumed = $this->command('catalog:reindex:resume');
        self::assertSame(Command::SUCCESS, $resumed->execute([]), $resumed->getDisplay());
        $again = $this->command('catalog:reindex:resume');
        self::assertSame(Command::SUCCESS, $again->execute([]), 'a second resumption only sends one more finalization');
        $this->work();

        self::assertSame(SearchRebuildStatus::Succeeded, $this->runs->get($run->id)?->status);
        self::assertSame($ids, $this->indexedIds());
        self::assertSame(Command::FAILURE, $resume->execute([]), 'nothing left to resume');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function restoredStages(): iterable
    {
        foreach (['producing', 'running', 'finalizing', 'verified', 'activating', 'activated'] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('restoredStages')]
    public function testADatabaseRestoredWithAnActiveRunIsReleasedForTheSynchronousRebuild(string $status): void
    {
        $ids = $this->createProducts(2);
        $previous = $this->maintenance->activeIndex();
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '1']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        // The run as the backup holds it, at any of its stages.
        $this->connection()->executeStatement('UPDATE catalog_search_rebuild_run SET status = ? WHERE id = ?', [$status, $run->id]);

        $abort = $this->command('catalog:reindex:abort');
        self::assertSame(Command::SUCCESS, $abort->execute(['--restored' => true]), $abort->getDisplay());

        self::assertSame(SearchRebuildStatus::Aborted, $this->runs->get($run->id)?->status);
        self::assertFalse($this->indexExists($run->targetIndex));
        // What the backup holds of the run (its batches, its finalization) changes nothing any more.
        $this->handle(new Envelope(new FinalizeSearchRebuild($run->id)));
        $this->work();
        self::assertFalse($this->indexExists($run->targetIndex));
        self::assertSame($previous, $this->maintenance->activeIndex());

        $rebuild = $this->command('catalog:reindex');
        self::assertSame(Command::SUCCESS, $rebuild->execute([]), $rebuild->getDisplay());
        self::assertSame($ids, $this->indexedIds());
        self::assertSame(Command::SUCCESS, $abort->execute(['--restored' => true]), 'no active run: nothing to do');
    }

    public function testAWorkerThatDiesAfterTheWriteBeforeTheCountCountsTheBatchOnceOnTheRedelivery(): void
    {
        $ids = $this->createProducts(3);
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '2']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        $es = new HookedMaintenance($this->maintenance);
        $es->afterWrite = static function (): void {
            throw new \RuntimeException('The worker was killed.');
        };

        try {
            $this->batchOf($es, $this->transport('indexing')->getSent()[0]);
            self::fail('Expected the worker to die.');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $this->runs->get($run->id)?->doneBatches, 'written, not counted');

        $this->work();

        $ended = $this->runs->get($run->id);
        self::assertSame(SearchRebuildStatus::Succeeded, $ended?->status);
        self::assertSame([2, 3], [$ended->doneBatches, $ended->documents]);
        self::assertSame($ids, $this->indexedIds());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function batchFailures(): iterable
    {
        yield 'transient (the transport retries it)' => [new SearchUnavailable('Elasticsearch timed out.')];
        yield 'permanent (failed, then retried by hand)' => [new SearchIndexError('A mapping conflict.')];
    }

    #[DataProvider('batchFailures')]
    public function testAFailedBatchCountsNothingAndItsRetryCompletesTheRun(\Throwable $failure): void
    {
        $ids = $this->createProducts(3);
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '2']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        [$failing, $other] = array_values($this->transport('indexing')->getSent());
        $es = new HookedMaintenance($this->maintenance);
        $es->beforeWrite = static function () use ($failure): void {
            throw $failure;
        };

        try {
            $this->batchOf($es, $failing);
            self::fail('Expected the batch to fail.');
        } catch (SearchUnavailable|SearchIndexError) {
        }
        $this->handle($other);
        $stalled = $this->runs->get($run->id);
        self::assertSame([SearchRebuildStatus::Running, 1], [$stalled?->status, $stalled?->doneBatches]);

        $this->handle($failing);
        $this->work(); // the copies still queued count nothing

        $ended = $this->runs->get($run->id);
        self::assertSame(SearchRebuildStatus::Succeeded, $ended?->status);
        self::assertSame(3, $ended->documents);
        self::assertSame($ids, $this->indexedIds());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function abortMoments(): iterable
    {
        yield 'before the bulk request' => [true];
        yield 'after the bulk request, before the count' => [false];
    }

    #[DataProvider('abortMoments')]
    public function testAnAbortDuringTheWriteOfABatchCountsNothingAndLeavesNoIndex(bool $beforeTheRequest): void
    {
        $this->createProducts(3);
        $previous = $this->maintenance->activeIndex();
        $this->command('catalog:reindex')->execute(['--async' => true, '--batch-size' => '2']);
        $run = $this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE);
        self::assertNotNull($run);
        $es = new HookedMaintenance($this->maintenance);
        $abort = function (): void {
            $tester = $this->command('catalog:reindex:abort');
            self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]), $tester->getDisplay());
        };
        if ($beforeTheRequest) {
            $es->beforeWrite = $abort;
        } else {
            $es->afterWrite = $abort;
        }

        $this->batchOf($es, $this->transport('indexing')->getSent()[0]);

        $ended = $this->runs->get($run->id);
        self::assertSame([SearchRebuildStatus::Aborted, 0], [$ended?->status, $ended?->doneBatches]);
        self::assertFalse($this->indexExists($run->targetIndex), 'the write did not leave an index behind');
        $this->work();
        self::assertFalse($this->indexExists($run->targetIndex));
        self::assertSame($previous, $this->maintenance->activeIndex());
    }

    public function testTheBatchSizeOutsideItsRangeIsRefused(): void
    {
        foreach ([['--async' => true, '--batch-size' => '0'], ['--async' => true, '--batch-size' => '5001'], ['--batch-size' => '-1'], ['--wait' => true]] as $input) {
            $tester = $this->command('catalog:reindex');
            self::assertSame(Command::FAILURE, $tester->execute($input), $tester->getDisplay());
        }
        self::assertNull($this->runs->active(AsyncProductSearchIndexRebuild::DOCUMENT_TYPE));
    }

    /**
     * Handles what waits in the transports as worker-indexing does, the finalization first, until both are empty.
     */
    private function work(): void
    {
        for ($round = 0; $round < 100; ++$round) {
            $envelopes = [...$this->drain('indexing_finalize'), ...$this->drain('indexing')];
            if ([] === $envelopes) {
                return;
            }
            foreach ($envelopes as $envelope) {
                $this->handle($envelope);
            }
        }
        self::fail('The transports did not empty.');
    }

    /**
     * @return list<Envelope>
     */
    private function drain(string $name): array
    {
        $transport = $this->transport($name);
        $envelopes = array_values([...$transport->get()]);
        foreach ($envelopes as $envelope) {
            $transport->ack($envelope);
        }

        return $envelopes;
    }

    private function handle(Envelope $envelope): void
    {
        $bus = self::getContainer()->get('indexing.bus');
        \assert($bus instanceof MessageBusInterface);
        $bus->dispatch($envelope->withoutAll(ReceivedStamp::class)->with(new ReceivedStamp('indexing')));
    }

    /**
     * Handles one batch as worker-indexing does, with the given maintenance of the index.
     */
    private function batchOf(HookedMaintenance $es, Envelope $envelope): void
    {
        $message = $envelope->getMessage();
        \assert($message instanceof IndexDocuments);
        $offers = self::getContainer()->get(QueryBusProductOfferFacts::class);
        \assert($offers instanceof QueryBusProductOfferFacts);
        $source = self::getContainer()->get(ProductSearchSource::class);
        \assert($source instanceof ProductSearchSource);
        $batch = new ProductRebuildBatch($this->runs, $source, new ProductBatchIndexer($es, new ProductDocumentBuilder(), $offers), $es, new FixedCatalogClock(new \DateTimeImmutable()));
        $batch->index($message->runId, $message->batch, $message->ids);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function command(string $name): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester(new Application(self::$kernel)->find($name));
    }

    /**
     * @return list<string> sorted
     */
    private function createProducts(int $count): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $products = new DoctrineProductRepository($entityManager);
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $product = ProductFactory::createOne(['mainCategory' => $category]);
            $products->save($product, null, null, new \DateTimeImmutable());
            $ids[] = $product->id()->toString();
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string> sorted
     */
    private function indexedIds(): array
    {
        $client = $this->client();
        $client->indices()->refresh(['index' => 'test_products']);
        $response = $client->search(['index' => 'test_products', 'body' => ['size' => 100, '_source' => false, 'sort' => [['id' => 'asc']]]]);
        \assert($response instanceof Elasticsearch);
        $result = $response->asArray()['hits'] ?? null;
        \assert(\is_array($result) && \is_array($result['hits']));
        $ids = [];
        foreach ($result['hits'] as $hit) {
            \assert(\is_array($hit) && \is_string($hit['_id']));
            $ids[] = $hit['_id'];
        }

        return $ids;
    }

    private function indexExists(string $index): bool
    {
        $response = $this->client()->indices()->exists(['index' => $index]);
        \assert($response instanceof Elasticsearch);

        return $response->asBool();
    }

    private function client(): \Elastic\Elasticsearch\Client
    {
        $factory = self::getContainer()->get('test.'.ElasticsearchClientFactory::class);
        \assert($factory instanceof ElasticsearchClientFactory);

        return $factory->create(30.0);
    }
}

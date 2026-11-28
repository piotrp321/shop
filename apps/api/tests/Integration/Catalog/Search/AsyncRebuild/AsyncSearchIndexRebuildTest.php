<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search\AsyncRebuild;

use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchRebuildRuns;
use App\Catalog\Application\Search\AsyncProductSearchIndexRebuild;
use App\Catalog\Application\Search\SearchRebuildStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Shared\Infrastructure\Indexing\IndexDocuments;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
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

    #[\PHPUnit\Framework\Attributes\DataProvider('catalogSizes')]
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

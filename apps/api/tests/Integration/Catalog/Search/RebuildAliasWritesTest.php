<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\ReindexOutcome;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\SystemClock;
use App\Tests\Support\InMemory\CallbackProductSearchSource;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\TestCase;

/**
 * Single writes while the index is rebuilt (W19a, spec "Zapisy w trakcie przebudowy (W19a, M5)"), on the real
 * Elasticsearch: the rebuild (ProductSearchIndexRebuilder over the real maintenance) and the single writes
 * (ProductIndexing over the real indexer) read the same products, whose state the test changes between their reads.
 */
final class RebuildAliasWritesTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private const array IDS = [
        '0198a1f0-0000-7000-8000-000000000001',
        '0198a1f0-0000-7000-8000-000000000002',
        '0198a1f0-0000-7000-8000-000000000003',
        '0198a1f0-0000-7000-8000-000000000004',
    ];

    /** @var array<string, string> id => the current name of the product */
    private array $names = [];
    private FixedCatalogClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedCatalogClock();
        foreach (self::IDS as $id) {
            $this->names[$id] = 'Initial '.$id;
        }
        $this->maintenance()->setup();
    }

    public function testAWriteBeforeTheBatchOfItsProductIsInTheNewIndexAndItsStaleBatchItemDoesNotFailTheRebuild(): void
    {
        $http = $this->bulks([]);
        $http->onBulk = function (int $bulk): void {
            if (1 === $bulk) {
                // The batch has read the product; the change is committed and reindexed before the batch is written.
                $this->change(self::IDS[0], 'Changed before its batch');
            }
        };

        $report = $this->rebuilder($http)->rebuild(2);

        self::assertSame(ActivationResult::Activated, $report->outcome, (string) $report->failure);
        self::assertSame('Changed before its batch', $this->activeName(self::IDS[0]));
        self::assertSame('Initial '.self::IDS[1], $this->activeName(self::IDS[1]));
        self::assertSame([], $this->rebuildAliasTargets());
    }

    public function testAWriteAfterTheBatchOfItsProductIsInTheNewIndex(): void
    {
        $http = $this->bulks([]);
        $http->onBulk = function (int $bulk): void {
            if (2 === $bulk) {
                $this->change(self::IDS[0], 'Changed after its batch');
            }
        };

        $report = $this->rebuilder($http)->rebuild(2);

        self::assertSame(ActivationResult::Activated, $report->outcome, (string) $report->failure);
        self::assertSame('Changed after its batch', $this->activeName(self::IDS[0]));
    }

    public function testAWriteBeforeTheRetryOfATransientlyRejectedBatchItemIsInTheNewIndex(): void
    {
        // The first batch has its first item rejected (429); the product changes before that item is written again,
        // so the retried item is older than the single write (409) and stays as the single write left it.
        $http = $this->bulks([['transient' => [0]]]);
        $http->onBulk = function (int $bulk): void {
            if (2 === $bulk) {
                $this->change(self::IDS[0], 'Changed before the retry');
            }
        };

        $report = $this->rebuilder($http)->rebuild(2);

        self::assertSame(ActivationResult::Activated, $report->outcome, (string) $report->failure);
        self::assertSame([1, 1, 2], $http->forwardedItems);
        self::assertSame('Changed before the retry', $this->activeName(self::IDS[0]));
    }

    /**
     * The read of a single write happens before the alias switch, its _bulk after the switch and the deletion of the
     * previous index: the item of the rebuild alias (gone with the switch) answers 404 and is skipped, the item of the
     * alias writes to the new index.
     */
    public function testAWriteReadBeforeTheSwitchAndWrittenAfterTheDeletionOfThePreviousIndexIsInTheNewIndex(): void
    {
        $previous = $this->maintenance()->activeIndex();
        $new = $this->maintenance()->createIndex();
        $documents = array_map(fn (string $id) => new ProductDocumentBuilder()->build($this->snapshot($id), null, ProductIndexing::version($this->clock->now())), self::IDS);
        self::assertTrue($this->maintenance()->write($new, $documents)->allIndexed());
        $this->maintenance()->verify($new, \count(self::IDS));
        $offers = new InMemoryProductOfferFacts();
        $offers->onRead = function () use ($new, $previous): void {
            self::assertSame(ActivationResult::Activated, $this->maintenance()->activate($new, $previous));
            $this->maintenance()->deleteIndex($previous);
        };
        $this->clock->advance('+1 second');
        $this->names[self::IDS[0]] = 'Read before the switch';

        self::assertSame(ReindexOutcome::Indexed, $this->indexing($offers)->reindex(self::IDS[0]));

        self::assertSame([$new], $this->aliasTargets());
        self::assertSame('Read before the switch', $this->activeName(self::IDS[0]));
    }

    /**
     * A writer process changes the products and reindexes each change (a single write, retried on a transient
     * failure like a Messenger message) in a loop while this process rebuilds the index several times. After every
     * rebuild the writer pauses after its current write, and every product in the active index must have the state
     * the writer committed last. The check does not depend on how the two processes interleave: the writer is paused
     * and every write it committed has completed.
     */
    public function testSingleWritesInParallelWithRebuildsLeaveEveryProductInItsLastStateAfterEachRebuild(): void
    {
        $products = 12;
        $rebuilds = 5;
        $ids = [];
        for ($i = 1; $i <= $products; ++$i) {
            $ids[] = \sprintf('0198a1f0-0000-7000-8000-%012d', 100 + $i);
        }
        $writer = new ParallelSearchWriter($ids, $this->alias());
        // Slow batches widen the time in which the writer's changes fall into the rebuild.
        $http = $this->bulks([]);
        $http->onBulk = static function (): void {
            usleep(10_000);
        };
        $rebuilder = new ProductSearchIndexRebuilder(
            new CallbackProductSearchSource($ids, $writer->snapshotOf(...)),
            $this->maintenance(longHttpClient: $http, sleeper: static function (int $milliseconds): void {
            }),
            self::freeLock(),
            new ProductDocumentBuilder(),
            new InMemoryProductOfferFacts(),
            new SystemClock(),
        );

        $writer->start();
        try {
            $writesDuringRebuilds = 0;
            for ($rebuild = 1; $rebuild <= $rebuilds; ++$rebuild) {
                $before = $writer->writes();
                $report = $rebuilder->rebuild(2);
                $writesDuringRebuilds += $writer->writes() - $before;
                self::assertSame(ActivationResult::Activated, $report->outcome, \sprintf('Rebuild %d: %s', $rebuild, $report->failure));

                $writer->pause();
                foreach ($ids as $id) {
                    self::assertSame($writer->nameOf($id), $this->activeName($id), \sprintf('Rebuild %d, product %s.', $rebuild, $id));
                }
                self::assertSame([], $this->rebuildAliasTargets());
                $writer->resume();
            }
        } finally {
            $outcome = $writer->stop();
        }

        self::assertSame([], $outcome['failures'], 'The writer had failures (a message in failed).');
        self::assertGreaterThan(0, $writesDuringRebuilds, 'No write fell into a rebuild.');
    }

    private function change(string $id, string $name): void
    {
        $this->clock->advance('+1 second');
        $this->names[$id] = $name;
        self::assertSame(ReindexOutcome::Indexed, $this->indexing(new InMemoryProductOfferFacts())->reindex($id));
    }

    private function indexing(InMemoryProductOfferFacts $offers): ProductIndexing
    {
        return new ProductIndexing(
            $this->source(),
            $offers,
            new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias()),
            new ProductDocumentBuilder(),
            $this->clock,
        );
    }

    private function rebuilder(ScriptedBulkFaultsHttpClient $http): ProductSearchIndexRebuilder
    {
        return new ProductSearchIndexRebuilder(
            $this->source(),
            $this->maintenance(longHttpClient: $http, sleeper: static function (int $milliseconds): void {
            }),
            self::freeLock(),
            new ProductDocumentBuilder(),
            new InMemoryProductOfferFacts(),
            $this->clock,
        );
    }

    private function source(): CallbackProductSearchSource
    {
        return new CallbackProductSearchSource(self::IDS, $this->snapshot(...));
    }

    private function snapshot(string $id): ProductSearchSnapshot
    {
        return ParallelSearchWriter::snapshot($id, $this->names[$id]);
    }

    /**
     * @param list<'unavailable'|'timeout'|array{transient?: list<int>, permanent?: list<int>, gone?: array<int, string>}> $script
     */
    private function bulks(array $script): ScriptedBulkFaultsHttpClient
    {
        return new ScriptedBulkFaultsHttpClient(self::clientFactory()->httpClient(30.0), $script);
    }

    private function activeName(string $id): mixed
    {
        // A real-time get: sees the last write whatever the refresh interval.
        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => $id]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        \assert(\is_array($source));

        return $source['name'] ?? null;
    }

    private static function freeLock(): SearchIndexRebuildLock
    {
        return new class implements SearchIndexRebuildLock {
            public function tryAcquire(): bool
            {
                return true;
            }

            public function release(): void
            {
            }
        };
    }
}

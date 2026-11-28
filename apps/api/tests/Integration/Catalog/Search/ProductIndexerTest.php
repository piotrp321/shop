<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\TestCase;

/**
 * Single writes: one _bulk with an item for the rebuild alias (<alias>-rebuild, only while a rebuild runs) and one for
 * the alias, in this order, with the same external_gte version (spec "Zapisy w trakcie przebudowy (W19a, M5)").
 */
final class ProductIndexerTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private const string ID = '0198a1f0-0000-7000-8000-000000000001';

    public function testOlderVersionDoesNotOverwriteANewerOne(): void
    {
        $this->maintenance()->setup();

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index(self::document(self::ID, 2, 'Second')));
        self::assertSame(IndexOutcome::Stale, $this->indexer()->index(self::document(self::ID, 1, 'First')));

        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        \assert(\is_array($source));
        self::assertSame(2, $stored['_version']);
        self::assertSame(2, $source['version']);
        self::assertSame('Second', $source['name']);
    }

    public function testTheSameVersionAgainIsWritten(): void
    {
        // Two handlings that took the same timestamp read the same or newer data; the later write wins.
        $this->maintenance()->setup();

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index(self::document(self::ID, 3, 'First')));
        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index(self::document(self::ID, 3, 'Second')));

        $this->refresh();
        self::assertSame(1, $this->indexer()->countDocuments());
        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        \assert(\is_array($source));
        self::assertSame('Second', $source['name']);
    }

    public function testWriteWithoutTheAliasIsRejectedAndCreatesNoIndex(): void
    {
        try {
            $this->indexer()->index(self::document(self::ID, 1));
            self::fail('The write went through without the alias.');
        } catch (SearchIndexError) {
        }

        self::assertFalse($this->indexExists($this->alias()));
        self::assertFalse($this->indexExists($this->rebuildAlias()));
    }

    public function testWithoutARebuildTheWriteIsOneBulkWhoseRebuildItemIsSkipped(): void
    {
        $this->maintenance()->setup();
        $http = $this->bulks([]);

        self::assertSame(IndexOutcome::Indexed, $this->indexerWith($http)->index(self::document(self::ID, 4, 'Only')));

        // Both items in one request; the rebuild alias does not exist, so its item answers 404 and is skipped.
        self::assertSame([2], $http->forwardedItems);
        self::assertSame('Only', $this->storedName($this->alias()));
        self::assertFalse($this->indexExists($this->rebuildAlias()), 'The write created an index named like the rebuild alias.');
    }

    public function testDuringARebuildTheWriteGoesToTheNewIndexFirstAndToTheAliasWithTheSameVersion(): void
    {
        $this->maintenance()->setup();
        $active = $this->maintenance()->activeIndex();
        $new = $this->maintenance()->createIndex();
        $http = $this->bulks([]);

        self::assertSame(IndexOutcome::Indexed, $this->indexerWith($http)->index(self::document(self::ID, 7, 'Both')));

        self::assertSame([$this->rebuildAlias(), $this->alias()], $http->bulkTargets[0] ?? null);
        foreach ([$new, $active] as $index) {
            $stored = self::esClient()->get(['index' => $index, 'id' => self::ID]);
            \assert($stored instanceof Elasticsearch);
            self::assertSame(7, $stored['_version'], $index);
            self::assertSame('Both', $this->storedName($index));
        }
    }

    public function testANewerVersionInTheNewIndexSkipsTheRebuildItemAndTheAliasDecidesTheOutcome(): void
    {
        $this->maintenance()->setup();
        $new = $this->maintenance()->createIndex();
        // A batch of the rebuild read the product later than this write did.
        $this->maintenance()->write($new, [self::document(self::ID, 9, 'Batch')]);

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index(self::document(self::ID, 5, 'Single')));

        self::assertSame('Batch', $this->storedName($new));
        self::assertSame('Single', $this->storedName($this->alias()));
    }

    public function testATransientFailureOfTheRebuildItemFailsTheWriteTransiently(): void
    {
        $this->maintenance()->setup();
        $this->maintenance()->createIndex();

        $this->expectException(SearchUnavailable::class);

        $this->indexerWith($this->bulks([['transient' => [0]]]))->index(self::document(self::ID, 1));
    }

    public function testAPermanentFailureOfTheRebuildItemFailsTheWritePermanently(): void
    {
        $this->maintenance()->setup();
        $this->maintenance()->createIndex();

        $this->expectException(SearchIndexError::class);

        $this->indexerWith($this->bulks([['permanent' => [0]]]))->index(self::document(self::ID, 1));
    }

    public function testAFailureOfTheAliasItemFailsTheWriteEvenWhenTheRebuildItemIsWritten(): void
    {
        $this->maintenance()->setup();
        $new = $this->maintenance()->createIndex();

        try {
            $this->indexerWith($this->bulks([['transient' => [1]]]))->index(self::document(self::ID, 1, 'New'));
            self::fail('The write did not fail.');
        } catch (SearchUnavailable) {
        }

        self::assertSame('New', $this->storedName($new));
    }

    /**
     * The alias switch and the deletion of the previous index fall between the resolution of the aliases of the bulk
     * (one cluster state) and the write of the item of the alias on its shard: the item of the rebuild alias reached
     * the new index, the item of the alias answers 404 for the deleted index. The write is retried and goes through.
     */
    public function testAnAliasItemOfAnIndexDeletedAfterTheSwitchFailsTheWriteTransientlyAndTheRetryGoesToTheNewIndex(): void
    {
        $this->maintenance()->setup();
        $previous = $this->maintenance()->activeIndex();
        $new = $this->maintenance()->createIndex();
        $http = $this->bulks([['gone' => [1 => $previous]]]);
        $http->afterForward = function () use ($new, $previous): void {
            self::assertSame(ActivationResult::Activated, $this->maintenance()->activate($new, $previous));
            $this->maintenance()->deleteIndex($previous);
        };

        try {
            $this->indexerWith($http)->index(self::document(self::ID, 3, 'Switched'));
            self::fail('The write did not fail.');
        } catch (SearchUnavailable $e) {
            self::assertStringContainsString($previous, $e->getMessage());
        }

        self::assertSame('Switched', $this->storedName($new));
        self::assertSame(IndexOutcome::Indexed, $this->indexerWith($http)->index(self::document(self::ID, 3, 'Switched')));
        self::assertSame([$new], $this->aliasTargets());
        self::assertSame('Switched', $this->storedName($this->alias()));
    }

    public function testWriteToAPlainIndexNamedLikeTheAliasIsRejected(): void
    {
        self::esClient()->indices()->create(['index' => $this->alias()]);

        try {
            $this->indexer()->index(self::document(self::ID, 1));
            self::fail('The write went to a plain index.');
        } catch (SearchIndexError) {
        }

        self::esClient()->indices()->refresh(['index' => $this->alias()]);
        $count = self::esClient()->count(['index' => $this->alias()]);
        \assert($count instanceof Elasticsearch);
        self::assertSame(0, $count['count']);
    }

    public function testBulkReportsEveryItemAndKeepsTheValidDocuments(): void
    {
        $this->maintenance()->setup();
        $index = $this->maintenance()->activeIndex();
        $good = [self::document('0198a1f0-0000-7000-8000-00000000000a', 1), self::document('0198a1f0-0000-7000-8000-00000000000c', 1)];
        // A keyword term longer than Lucene's 32766-byte limit: rejected for this document only.
        $bad = self::document('0198a1f0-0000-7000-8000-00000000000b', 1, slug: str_repeat('a', 40_000));

        $result = $this->maintenance()->write($index, [$good[0], $bad, $good[1]]);

        self::assertSame([$good[0]->id, $good[1]->id], $result->indexed);
        self::assertSame([$bad->id], array_keys($result->permanentFailures));
        self::assertSame([], $result->stale);
        self::assertSame([], $result->transientFailures);
        self::assertFalse($result->allIndexed());
        $this->refresh();
        self::assertSame(2, $this->indexer()->countDocuments());
    }

    public function testBulkWithAnOlderVersionReportsTheItemAsStale(): void
    {
        $this->maintenance()->setup();
        $index = $this->maintenance()->activeIndex();
        $this->maintenance()->write($index, [self::document(self::ID, 2), self::document('0198a1f0-0000-7000-8000-000000000003', 2)]);

        $result = $this->maintenance()->write($index, [
            self::document(self::ID, 1),
            self::document('0198a1f0-0000-7000-8000-000000000003', 2),
            self::document('0198a1f0-0000-7000-8000-000000000002', 1),
        ]);

        self::assertSame([self::ID], $result->stale);
        self::assertSame(['0198a1f0-0000-7000-8000-000000000003', '0198a1f0-0000-7000-8000-000000000002'], $result->indexed);
    }

    public function testEmptyBulkWritesNothing(): void
    {
        $this->maintenance()->setup();

        self::assertTrue($this->maintenance()->write($this->maintenance()->activeIndex(), [])->allIndexed());
    }

    private function indexer(): ElasticsearchProductIndexer
    {
        return new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
    }

    private function indexerWith(ScriptedBulkFaultsHttpClient $http): ElasticsearchProductIndexer
    {
        return new ElasticsearchProductIndexer(self::clientFactory()->createWith($http), $this->alias());
    }

    /**
     * @param list<'unavailable'|'timeout'|array{transient?: list<int>, permanent?: list<int>, gone?: array<int, string>}> $script
     */
    private function bulks(array $script): ScriptedBulkFaultsHttpClient
    {
        return new ScriptedBulkFaultsHttpClient(self::clientFactory()->httpClient(2.0), $script);
    }

    private function rebuildAlias(): string
    {
        return $this->alias().'-rebuild';
    }

    private function storedName(string $index): mixed
    {
        $stored = self::esClient()->get(['index' => $index, 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        \assert(\is_array($source));

        return $source['name'] ?? null;
    }

    private function refresh(): void
    {
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    private static function document(string $id, int $version, string $name = 'Name', ?string $slug = null): ProductDocument
    {
        return new ProductDocument($id, $version, $name, $slug ?? 'slug-'.$id, '', 'c', 'c', ['c'], ['c'], ['key' => 'originals/x.jpg', 'alt' => 'Alt'], [], [], null, ProductStatus::Published);
    }
}

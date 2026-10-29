<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\TestCase;

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

    public function testSameVersionTwiceIsStaleWithoutAnError(): void
    {
        $this->maintenance()->setup();

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index(self::document(self::ID, 3)));
        self::assertSame(IndexOutcome::Stale, $this->indexer()->index(self::document(self::ID, 3)));

        $this->refresh();
        self::assertSame(1, $this->indexer()->countDocuments());
    }

    public function testWriteWithoutTheAliasIsRejectedAndCreatesNoIndex(): void
    {
        try {
            $this->indexer()->index(self::document(self::ID, 1));
            self::fail('The write went through without the alias.');
        } catch (SearchIndexError) {
        }

        self::assertFalse($this->indexExists($this->alias()));
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

    public function testBulkRepeatingAVersionReportsTheItemAsStale(): void
    {
        $this->maintenance()->setup();
        $index = $this->maintenance()->activeIndex();
        $this->maintenance()->write($index, [self::document(self::ID, 2)]);

        $result = $this->maintenance()->write($index, [self::document(self::ID, 2), self::document('0198a1f0-0000-7000-8000-000000000002', 1)]);

        self::assertSame([self::ID], $result->stale);
        self::assertSame(['0198a1f0-0000-7000-8000-000000000002'], $result->indexed);
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

    private function refresh(): void
    {
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    private static function document(string $id, int $version, string $name = 'Name', ?string $slug = null): ProductDocument
    {
        return new ProductDocument($id, $version, $name, $slug ?? 'slug-'.$id, '', 'c', ['c'], ['c'], ['key' => 'originals/x.jpg', 'alt' => 'Alt']);
    }
}

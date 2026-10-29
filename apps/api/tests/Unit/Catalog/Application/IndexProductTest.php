<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\EventHandler\IndexProduct;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductCreated;
use App\Tests\Support\InMemory\RecordingProductIndexer;
use App\Tests\Unit\Catalog\Search\InMemoryProductSearchSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class IndexProductTest extends TestCase
{
    private const string PRODUCT_ID = '10000000-0000-4000-8000-000000000001';
    private const string MAIN_ID = '00000000-0000-4000-8000-000000000001';

    private RecordingProductIndexer $indexer;
    private IndexProduct $handler;

    protected function setUp(): void
    {
        $this->indexer = new RecordingProductIndexer();
        $this->handler = $this->handlerFor([self::snapshot(self::PRODUCT_ID, 4)]);
    }

    public function testIndexesTheCurrentStateOfTheProductWithItsVersion(): void
    {
        ($this->handler)($this->event());

        self::assertCount(1, $this->indexer->indexed);
        $document = $this->indexer->indexed[0];
        self::assertSame(self::PRODUCT_ID, $document->id);
        self::assertSame(4, $document->version);
        self::assertSame('Buty biegowe', $document->name);
    }

    public function testStaleOutcomeEndsHandlingWithoutAnError(): void
    {
        $this->indexer->willReturn(IndexOutcome::Stale);

        ($this->handler)($this->event());

        self::assertCount(1, $this->indexer->indexed);
    }

    public function testTheSameEventTwiceWritesTheSameVersionTwice(): void
    {
        $this->indexer->willReturn(IndexOutcome::Indexed, IndexOutcome::Stale);

        ($this->handler)($this->event());
        ($this->handler)($this->event());

        self::assertCount(2, $this->indexer->indexed);
        self::assertSame(4, $this->indexer->indexed[1]->version);
    }

    public function testTransientSearchFailureIsLeftToTheRetryStrategy(): void
    {
        $this->indexer->willReturn(new SearchUnavailable('Elasticsearch is down.'));

        $this->expectException(SearchUnavailable::class);

        ($this->handler)($this->event());
    }

    public function testPermanentSearchFailureIsNotRetried(): void
    {
        $failure = new SearchIndexError('Mapping rejected.');
        $this->indexer->willReturn($failure);

        try {
            ($this->handler)($this->event());
            self::fail('The permanent failure was swallowed.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testMissingProductIsNotRetriedAndNothingIsWritten(): void
    {
        $handler = $this->handlerFor([]);

        try {
            $handler($this->event());
            self::fail('A missing product was ignored.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        self::assertSame([], $this->indexer->indexed);
    }

    /**
     * @param list<ProductSearchSnapshot> $snapshots
     */
    private function handlerFor(array $snapshots): IndexProduct
    {
        return new IndexProduct(new InMemoryProductSearchSource($snapshots), $this->indexer, new ProductDocumentBuilder());
    }

    private function event(): ProductCreated
    {
        return new ProductCreated(self::PRODUCT_ID, new \DateTimeImmutable('2026-10-29T12:00:00+00:00'));
    }

    private static function snapshot(string $id, int $version): ProductSearchSnapshot
    {
        return new ProductSearchSnapshot($id, $version, 'Buty biegowe', 'buty-biegowe', 'Lekkie', self::MAIN_ID, [], [[self::MAIN_ID]], null);
    }
}

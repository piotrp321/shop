<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\ProductBatchIndexer;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use PHPUnit\Framework\TestCase;

final class ProductBatchIndexerTest extends TestCase
{
    public function testAStaleItemCountsAsWritten(): void
    {
        $maintenance = new FakeSearchIndexMaintenance();
        $maintenance->writeResult = new BulkResult(indexed: [self::ID_1], stale: [self::ID_2]);

        self::assertSame(2, $this->indexer($maintenance)->write('products_new', self::snapshots(), 7));
    }

    public function testItemsStillFailingTransientlyAreATransientFailureThatTheTransportRetries(): void
    {
        $maintenance = new FakeSearchIndexMaintenance();
        $maintenance->writeResult = new BulkResult(indexed: [self::ID_1], transientFailures: [self::ID_2 => '429']);

        $this->expectException(SearchUnavailable::class);

        $this->indexer($maintenance)->write('products_new', self::snapshots(), 7);
    }

    public function testAPermanentlyRejectedItemIsAPermanentFailure(): void
    {
        $maintenance = new FakeSearchIndexMaintenance();
        $maintenance->writeResult = new BulkResult(transientFailures: [self::ID_1 => '429'], permanentFailures: [self::ID_2 => 'mapper_parsing_exception']);

        $this->expectException(SearchIndexError::class);

        $this->indexer($maintenance)->write('products_new', self::snapshots(), 7);
    }

    public function testNoSnapshotsWriteNothing(): void
    {
        $maintenance = new FakeSearchIndexMaintenance();

        self::assertSame(0, $this->indexer($maintenance)->write('products_new', [], 7));
        self::assertSame([], $maintenance->calls);
    }

    private const string ID_1 = '00000000-0000-4000-8000-000000000001';
    private const string ID_2 = '00000000-0000-4000-8000-000000000002';

    private function indexer(FakeSearchIndexMaintenance $maintenance): ProductBatchIndexer
    {
        return new ProductBatchIndexer($maintenance, new ProductDocumentBuilder(), new InMemoryProductOfferFacts());
    }

    /**
     * @return list<ProductSearchSnapshot>
     */
    private static function snapshots(): array
    {
        return array_map(
            static fn (string $id): ProductSearchSnapshot => new ProductSearchSnapshot($id, 'Kurtka', 'kurtka-'.$id, '', '00000000-0000-4000-8000-0000000000c1', [], [['00000000-0000-4000-8000-0000000000c1']], null, ['Kurtki'], [], [['id' => $id, 'values' => []]], ProductStatus::Published),
            [self::ID_1, self::ID_2],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\SyncStockRecords;
use App\Inventory\Application\Port\DirectoryPage;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Application\StockRecords\StockRecordsSynced;
use App\Inventory\Application\StockRecords\StockRecordSync;
use App\Inventory\Domain\Stock\ProductId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class StockRecordSyncTest extends TestCase
{
    private const string TENT = '10000000-0000-4000-8000-000000000001';
    private const string KAYAK = '10000000-0000-4000-8000-000000000002';
    private const string MAP = '10000000-0000-4000-8000-000000000003';

    private PagedDirectory $directory;
    private SyncingBus $bus;

    protected function setUp(): void
    {
        $this->directory = new PagedDirectory([[self::TENT, self::KAYAK], [self::MAP]]);
        $this->bus = new SyncingBus();
    }

    public function testTheProductsOfAnEventAreSyncedInOneCommand(): void
    {
        $synced = new StockRecordSync($this->directory, $this->bus)->ofProducts([ProductId::fromString(self::TENT), ProductId::fromString(self::KAYAK)]);

        self::assertEquals([new SyncStockRecords([self::TENT, self::KAYAK])], $this->bus->dispatched);
        self::assertSame([], $this->directory->reads, 'the handler reads the variants, under the locks of the products');
        self::assertEquals(new StockRecordsSynced(2, 2, 0), $synced);
    }

    public function testTheCatalogIsSyncedPageByPageInTransactionsOfOnePageAndTheResultsAddUp(): void
    {
        $progress = [];

        $synced = new StockRecordSync($this->directory, $this->bus)->ofCatalog(static function (StockRecordsSynced $sofar) use (&$progress): void {
            $progress[] = $sofar;
        });

        self::assertSame([[null, null, null, StockRecordSync::BATCH_SIZE], [null, null, 'page-1', StockRecordSync::BATCH_SIZE]], $this->directory->requests);
        self::assertEquals([new SyncStockRecords([self::TENT, self::KAYAK]), new SyncStockRecords([self::MAP])], $this->bus->dispatched);
        self::assertEquals(new StockRecordsSynced(3, 3, 0), $synced);
        self::assertEquals([new StockRecordsSynced(2, 2, 0), new StockRecordsSynced(3, 3, 0)], $progress);
    }

    public function testAnEmptyCatalogDispatchesNothing(): void
    {
        $synced = new StockRecordSync(new PagedDirectory([[]]), $this->bus)->ofCatalog();

        self::assertSame([], $this->bus->dispatched);
        self::assertEquals(new StockRecordsSynced(0, 0, 0), $synced);
    }
}

/**
 * Pages of product ids, the next cursor `page-<n>` of every page but the last.
 */
final class PagedDirectory implements ProductDirectory
{
    /** @var list<array{?string, ?string, ?string, int}> */
    public array $requests = [];
    /** @var list<list<ProductId>> */
    public array $reads = [];

    /**
     * @param list<list<string>> $pages
     */
    public function __construct(private readonly array $pages)
    {
    }

    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage, ?string $sort = null): DirectoryPage
    {
        $this->requests[] = [$term, $categoryId, $after, $perPage];
        $index = null === $after ? 0 : (int) substr($after, 5);

        return new DirectoryPage(
            array_map(static fn (string $id): DirectoryProduct => new DirectoryProduct(ProductId::fromString($id), 'name'), $this->pages[$index]),
            $index + 1 < \count($this->pages) ? 'page-'.($index + 1) : null,
        );
    }

    public function variantsOf(array $productIds): array
    {
        $this->reads[] = $productIds;

        return [];
    }

    public function describe(array $productIds): array
    {
        throw new \LogicException('Not used by the sync.');
    }
}

/**
 * Answers SyncStockRecords as its handler would for products of one variant each on empty records: every product
 * found, one record written for each, nothing removed.
 */
final class SyncingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        \assert($message instanceof SyncStockRecords);
        $this->dispatched[] = $message;
        $count = \count($message->productIds);

        return Envelope::wrap($message)->with(new HandledStamp(new StockRecordsSynced($count, $count, 0), 'handler'));
    }
}

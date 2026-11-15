<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\SyncStockRecords;
use App\Inventory\Application\Port\DirectoryPage;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\DirectoryVariant;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Application\StockRecords\StockRecordsSynced;
use App\Inventory\Application\StockRecords\StockRecordSync;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\VariantId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class StockRecordSyncTest extends TestCase
{
    private const string TENT = '10000000-0000-4000-8000-000000000001';
    private const string KAYAK = '10000000-0000-4000-8000-000000000002';
    private const string MAP = '10000000-0000-4000-8000-000000000003';
    private const string TENT_GREEN = '20000000-0000-4000-8000-000000000001';
    private const string TENT_RED = '20000000-0000-4000-8000-000000000002';
    private const string KAYAK_DEFAULT = '20000000-0000-4000-8000-000000000003';
    private const string MAP_DEFAULT = '20000000-0000-4000-8000-000000000004';

    private PagedDirectory $directory;
    private SyncingBus $bus;

    protected function setUp(): void
    {
        $this->directory = new PagedDirectory(
            [[self::TENT, self::KAYAK], [self::MAP]],
            [self::TENT => [self::TENT_GREEN, self::TENT_RED], self::KAYAK => [self::KAYAK_DEFAULT], self::MAP => [self::MAP_DEFAULT]],
        );
        $this->bus = new SyncingBus();
    }

    public function testTheProductsOfAnEventAreSyncedWithAllTheirVariantsInOneCommand(): void
    {
        $synced = new StockRecordSync($this->directory, $this->bus)->ofProducts([ProductId::fromString(self::TENT), ProductId::fromString(self::KAYAK)]);

        self::assertEquals(
            [new SyncStockRecords([self::TENT => [self::TENT_GREEN, self::TENT_RED], self::KAYAK => [self::KAYAK_DEFAULT]])],
            $this->bus->dispatched,
        );
        self::assertEquals(new StockRecordsSynced(2, 3, 0), $synced);
    }

    public function testProductsCatalogDoesNotHaveAreLeftOutAndWithoutAnyNothingIsDispatched(): void
    {
        $synced = new StockRecordSync($this->directory, $this->bus)->ofProducts([ProductId::fromString('10000000-0000-4000-8000-0000000000ff')]);

        self::assertSame([], $this->bus->dispatched);
        self::assertEquals(new StockRecordsSynced(0, 0, 0), $synced);
    }

    public function testTheCatalogIsSyncedPageByPageInTransactionsOfOnePageAndTheResultsAddUp(): void
    {
        $progress = [];

        $synced = new StockRecordSync($this->directory, $this->bus)->ofCatalog(static function (StockRecordsSynced $sofar) use (&$progress): void {
            $progress[] = $sofar;
        });

        self::assertSame([[null, null, null, StockRecordSync::BATCH_SIZE], [null, null, 'page-1', StockRecordSync::BATCH_SIZE]], $this->directory->requests);
        self::assertEquals(
            [
                new SyncStockRecords([self::TENT => [self::TENT_GREEN, self::TENT_RED], self::KAYAK => [self::KAYAK_DEFAULT]]),
                new SyncStockRecords([self::MAP => [self::MAP_DEFAULT]]),
            ],
            $this->bus->dispatched,
        );
        self::assertEquals(new StockRecordsSynced(3, 4, 0), $synced);
        self::assertEquals([new StockRecordsSynced(2, 3, 0), new StockRecordsSynced(3, 4, 0)], $progress);
    }

    public function testAnEmptyCatalogDispatchesNothing(): void
    {
        $synced = new StockRecordSync(new PagedDirectory([[]], []), $this->bus)->ofCatalog();

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

    /**
     * @param list<list<string>>          $pages
     * @param array<string, list<string>> $variants product id => variant ids
     */
    public function __construct(private readonly array $pages, private readonly array $variants)
    {
    }

    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage): DirectoryPage
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
        $found = [];
        foreach ($productIds as $id) {
            if (isset($this->variants[$id->toString()])) {
                $found[$id->toString()] = array_map(static fn (string $variant): DirectoryVariant => new DirectoryVariant(VariantId::fromString($variant), ''), $this->variants[$id->toString()]);
            }
        }

        return $found;
    }
}

/**
 * Answers SyncStockRecords as its handler would on empty records: every variant written, nothing removed.
 */
final class SyncingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        \assert($message instanceof SyncStockRecords);
        $this->dispatched[] = $message;
        $written = array_sum(array_map(\count(...), $message->variantsByProduct));

        return Envelope::wrap($message)->with(new HandledStamp(new StockRecordsSynced(\count($message->variantsByProduct), $written, 0), 'handler'));
    }
}

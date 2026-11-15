<?php

declare(strict_types=1);

namespace App\Inventory\Application\StockRecords;

use App\Inventory\Application\Command\SyncStockRecords;
use App\Inventory\Application\Port\DirectoryVariant;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Domain\Stock\ProductId;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Gives every variant of the products a stock record with its product (decision A9 of ADMIN-FEATURES): the variants
 * come from Catalog through the ProductDirectory port, the records are written by SyncStockRecords on the
 * `command.bus` (injected by the parameter name), one transaction per batch of products. Idempotent.
 *
 * The variants are read before the transaction. An edit committed between the read and the write publishes its own
 * ProductChanged, whose sync reads the variants again, so the records end as Catalog has them.
 */
final readonly class StockRecordSync
{
    /** Products per batch: the page size of the directory, and of one transaction. */
    public const int BATCH_SIZE = 100;

    public function __construct(
        private ProductDirectory $directory,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @param list<ProductId> $productIds 1 to 100; those Catalog does not have are left out
     */
    public function ofProducts(array $productIds): StockRecordsSynced
    {
        $variants = $this->directory->variantsOf($productIds);
        if ([] === $variants) {
            return new StockRecordsSynced(0, 0, 0);
        }

        $result = $this->commandBus->dispatch(new SyncStockRecords(array_map(
            static fn (array $list): array => array_map(static fn (DirectoryVariant $variant): string => $variant->id->toString(), $list),
            $variants,
        )))->last(HandledStamp::class)?->getResult();
        \assert($result instanceof StockRecordsSynced);

        return $result;
    }

    /**
     * Every product of Catalog, drafts too, in batches in the order of the directory (by name, then id), so the
     * catalog is never held in memory. A product renamed during the sync may be passed over or visited twice: its
     * ProductChanged syncs it anyway.
     *
     * @param (\Closure(StockRecordsSynced): void)|null $progress called with the running total after every batch
     */
    public function ofCatalog(?\Closure $progress = null): StockRecordsSynced
    {
        $total = new StockRecordsSynced(0, 0, 0);
        $after = null;
        do {
            $page = $this->directory->page(null, null, $after, self::BATCH_SIZE);
            if ([] !== $page->items) {
                $total = $total->plus($this->ofProducts(array_map(static fn ($product): ProductId => $product->id, $page->items)));
                if (null !== $progress) {
                    $progress($total);
                }
            }
            $after = $page->next;
        } while (null !== $after);

        return $total;
    }
}

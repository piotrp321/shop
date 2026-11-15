<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Application\Port\DirectoryVariant;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Application\Port\StockRecords;
use App\Inventory\Application\StockRecords\StockRecordsSynced;
use App\Inventory\Domain\Stock\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SyncStockRecordsHandler
{
    public function __construct(
        private StockRecords $records,
        private ProductDirectory $directory,
    ) {
    }

    /**
     * In the transaction of the command bus: the locks of the products first, then the variants from Catalog, then
     * both writes. The variants are read under the locks, so of two syncs of a product the later one reads what
     * Catalog had committed when the earlier one ended, and an older read can never be written over a newer one (a
     * removed variant kept, a new one's empty record removed). An edit committed after the read publishes its own
     * ProductChanged, whose sync reads the variants again.
     *
     * @throws \InvalidArgumentException when no product or more than 100 are given, or an id is not a UUID
     */
    public function __invoke(SyncStockRecords $command): StockRecordsSynced
    {
        $count = \count($command->productIds);
        if ($count < 1 || $count > SyncStockRecords::MAX_PRODUCTS) {
            throw new \InvalidArgumentException(\sprintf('Between 1 and %d products must be given.', SyncStockRecords::MAX_PRODUCTS));
        }
        $productIds = array_map(ProductId::fromString(...), $command->productIds);

        $this->records->lockProducts($productIds);
        $variants = $this->directory->variantsOf($productIds);
        if ([] === $variants) {
            return new StockRecordsSynced(0, 0, 0);
        }

        $variantsByProduct = array_map(
            static fn (array $list): array => array_map(static fn (DirectoryVariant $variant) => $variant->id, $list),
            $variants,
        );

        return new StockRecordsSynced(
            \count($variants),
            $this->records->ensure($variantsByProduct),
            $this->records->removeEmptyOfRemovedVariants($variantsByProduct),
        );
    }
}

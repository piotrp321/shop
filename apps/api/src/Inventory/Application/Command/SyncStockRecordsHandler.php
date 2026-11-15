<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Application\Port\StockRecords;
use App\Inventory\Application\StockRecords\StockRecordsSynced;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\VariantId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SyncStockRecordsHandler
{
    public function __construct(private StockRecords $records)
    {
    }

    /**
     * Both writes in the transaction of the command bus.
     *
     * @throws \InvalidArgumentException when no product or more than 100 are given, or an id is not a UUID
     */
    public function __invoke(SyncStockRecords $command): StockRecordsSynced
    {
        $count = \count($command->variantsByProduct);
        if ($count < 1 || $count > SyncStockRecords::MAX_PRODUCTS) {
            throw new \InvalidArgumentException(\sprintf('Between 1 and %d products must be given.', SyncStockRecords::MAX_PRODUCTS));
        }

        $variantsByProduct = [];
        foreach ($command->variantsByProduct as $productId => $variantIds) {
            $variantsByProduct[ProductId::fromString((string) $productId)->toString()] = array_map(VariantId::fromString(...), $variantIds);
        }

        return new StockRecordsSynced(
            $count,
            $this->records->ensure($variantsByProduct),
            $this->records->removeEmptyOfRemovedVariants($variantsByProduct),
        );
    }
}

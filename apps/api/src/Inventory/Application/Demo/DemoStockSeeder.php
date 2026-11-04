<?php

declare(strict_types=1);

namespace App\Inventory\Application\Demo;

use App\Inventory\Application\Command\CreateEmptyStock;
use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Application\Port\CatalogVariant;
use App\Inventory\Application\Port\VariantsForInventory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gives every Catalog variant, matched by its key, its demo stock through the `command.bus` (injected by the parameter
 * name): a receipt with the source `seed` and the key `seed:<variantId>` for a stock above 0, an empty stock record
 * for 0. Variants are read in batches, so the catalog is never held in memory.
 */
final readonly class DemoStockSeeder
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private VariantsForInventory $variants,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return list<string> keys of the variants no pattern matches, in id order
     */
    public function unmatchedKeys(DemoStockList $stock): array
    {
        $unmatched = [];
        foreach ($this->allVariants() as $variant) {
            if (null === $stock->quantityFor($variant->key)) {
                $unmatched[] = $variant->key;
            }
        }

        return $unmatched;
    }

    /**
     * Each stock is set in its own transaction. The variants must have no stock yet (see InventoryReset).
     *
     * @return int the number of stocks set
     *
     * @throws \RuntimeException at the first variant no pattern matches
     */
    public function seed(DemoStockList $stock): int
    {
        $count = 0;
        foreach ($this->allVariants() as $variant) {
            $quantity = $stock->quantityFor($variant->key)
                ?? throw new \RuntimeException(\sprintf('No demo stock pattern matches the variant "%s".', $variant->key));
            $variantId = $variant->id->toString();
            $this->commandBus->dispatch(
                $quantity > 0
                    ? ReceiveStock::bySeed($variantId, $quantity, 'seed:'.$variantId)
                    : new CreateEmptyStock($variantId),
            );
            ++$count;
        }

        return $count;
    }

    /**
     * @return iterable<CatalogVariant>
     */
    private function allVariants(): iterable
    {
        $after = null;
        do {
            $batch = $this->variants->page($after, self::BATCH_SIZE);
            yield from $batch;
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id;
        } while (self::BATCH_SIZE === \count($batch));
    }
}

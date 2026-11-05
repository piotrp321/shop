<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Application\Port\VariantCatalog;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\VariantId;
use App\Inventory\Domain\Stock\VariantNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateEmptyStockHandler
{
    public function __construct(
        private StockItemRepository $stock,
        private VariantCatalog $catalog,
    ) {
    }

    /**
     * Idempotent: a variant that has a stock keeps it unchanged. The row stays when the transaction commits, as the
     * stock at version 0 that lockOrCreate() stores.
     *
     * @throws VariantNotFound
     */
    public function __invoke(CreateEmptyStock $command): void
    {
        $variantId = VariantId::fromString($command->variantId);
        if (!$this->catalog->exists($variantId)) {
            throw new VariantNotFound();
        }

        $this->stock->lockOrCreate($variantId);
    }
}

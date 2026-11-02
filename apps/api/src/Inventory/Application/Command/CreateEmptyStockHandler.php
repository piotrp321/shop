<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Application\Port\ProductCatalog;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\ProductNotFound;
use App\Inventory\Domain\Stock\StockItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateEmptyStockHandler
{
    public function __construct(
        private StockItemRepository $stock,
        private ProductCatalog $catalog,
    ) {
    }

    /**
     * Idempotent: a product that has a stock keeps it unchanged. The row stays when the transaction commits, as the
     * stock at version 0 that lockOrCreate() stores.
     *
     * @throws ProductNotFound
     */
    public function __invoke(CreateEmptyStock $command): void
    {
        $productId = ProductId::fromString($command->productId);
        if (!$this->catalog->exists($productId)) {
            throw new ProductNotFound();
        }

        $this->stock->lockOrCreate($productId);
    }
}

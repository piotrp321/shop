<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Application\Port\ProductCatalog;
use App\Inventory\Domain\Stock\CorrectionBelowReserved;
use App\Inventory\Domain\Stock\CorrectionReason;
use App\Inventory\Domain\Stock\InvalidCorrectionReason;
use App\Inventory\Domain\Stock\InvalidStockNote;
use App\Inventory\Domain\Stock\InvalidStockQuantity;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\ProductNotFound;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockModifiedConcurrently;
use App\Inventory\Domain\Stock\StockNote;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CorrectStockHandler
{
    public function __construct(
        private StockItemRepository $stock,
        private ProductCatalog $catalog,
    ) {
    }

    /**
     * The version is compared once the stock is locked, so a change committed since the staff member's view is a
     * conflict and nothing is overwritten. A product without a stored stock is corrected with the null version; its
     * stock is created only when the count changes it (counting nothing stores nothing), and a stock created by
     * another transaction in the meantime is a conflict like any other change.
     *
     * @return StockView the stock as saved, or as stored when nothing changed
     *
     * @throws InvalidCorrectionReason
     * @throws InvalidStockNote
     * @throws ProductNotFound
     * @throws StockModifiedConcurrently
     * @throws InvalidStockQuantity
     * @throws CorrectionBelowReserved
     */
    public function __invoke(CorrectStock $command): StockView
    {
        $reason = CorrectionReason::fromString($command->reason);
        $note = StockNote::fromNullable($command->note);
        $origin = StockChangeOrigin::staff($command->actorId, $command->actorName);
        $productId = ProductId::fromString($command->productId);
        if (!$this->catalog->exists($productId)) {
            throw new ProductNotFound();
        }

        $item = $this->stock->lockMany([$productId])[0] ?? null;
        if (null === $item) {
            if (null !== $command->expectedVersion) {
                throw new StockModifiedConcurrently();
            }
            $unstored = StockItem::unstored($productId);
            if (!$unstored->correct($command->countedPhysical, $reason, $note, $origin)) {
                return StockView::unstored($unstored);
            }
            $item = $this->stock->lockOrCreate($productId);
            // An empty stock (0/0, version 0) committed meanwhile equals no stock at all, so the null version takes it.
            if (0 !== $item->version()) {
                throw new StockModifiedConcurrently();
            }
        } elseif ($command->expectedVersion !== $item->version()) {
            throw new StockModifiedConcurrently();
        }

        if ($item->correct($command->countedPhysical, $reason, $note, $origin)) {
            $this->stock->save($item);
        }

        return StockView::of($item);
    }
}

<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Application\Port\ProductCatalog;
use App\Inventory\Domain\Stock\InvalidReceiptKey;
use App\Inventory\Domain\Stock\InvalidStockNote;
use App\Inventory\Domain\Stock\InvalidStockQuantity;
use App\Inventory\Domain\Stock\PhysicalStockLimitExceeded;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\ProductNotFound;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\ReceiptKeyReused;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockNote;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReceiveStockHandler
{
    public function __construct(
        private StockItemRepository $stock,
        private ProductCatalog $catalog,
    ) {
    }

    /**
     * Locks the stock of the product (creating it if needed) before it looks the key up, so receipts of one product
     * run one after another and a receipt sent again sees the one sent first. A key used before answers with the
     * stock as it is now when the receipt is the same one, and is refused otherwise. The unique index of receipt keys
     * catches a key received into two products at the same time (see StockItemRepository::save()).
     *
     * @throws InvalidReceiptKey
     * @throws InvalidStockNote
     * @throws ProductNotFound
     * @throws ReceiptKeyReused
     * @throws InvalidStockQuantity
     * @throws PhysicalStockLimitExceeded
     */
    public function __invoke(ReceiveStock $command): ReceiveStockResult
    {
        $key = ReceiptKey::fromString($command->receiptKey);
        $note = StockNote::fromNullable($command->note);
        $origin = match ($command->source) {
            StockChangeSource::Staff => StockChangeOrigin::staff($command->actorId ?? '', $command->actorName ?? ''),
            StockChangeSource::Seed => StockChangeOrigin::seed(),
            StockChangeSource::System => throw new \LogicException('The system never receives stock.'),
        };
        $productId = ProductId::fromString($command->productId);
        if (!$this->catalog->exists($productId)) {
            throw new ProductNotFound();
        }

        $item = $this->stock->lockOrCreate($productId);

        $receipt = $this->stock->findReceipt($key);
        if (null !== $receipt) {
            if (!$receipt->isReceiptOf($productId, $command->quantity, $note)) {
                throw new ReceiptKeyReused();
            }

            return new ReceiveStockResult(StockView::of($item), true);
        }

        $item->receive($command->quantity, $key, $note, $origin);
        $this->stock->save($item);

        return new ReceiveStockResult(StockView::of($item), false);
    }
}

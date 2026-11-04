<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Application\Port\VariantCatalog;
use App\Inventory\Domain\Stock\InvalidReceiptKey;
use App\Inventory\Domain\Stock\InvalidStockNote;
use App\Inventory\Domain\Stock\InvalidStockQuantity;
use App\Inventory\Domain\Stock\PhysicalStockLimitExceeded;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\ReceiptKeyReused;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockNote;
use App\Inventory\Domain\Stock\VariantId;
use App\Inventory\Domain\Stock\VariantNotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReceiveStockHandler
{
    public function __construct(
        private StockItemRepository $stock,
        private VariantCatalog $catalog,
    ) {
    }

    /**
     * Locks the stock of the variant (creating it if needed) before it looks the key up, so receipts of one variant
     * run one after another and a receipt sent again sees the one sent first. A key used before answers with the
     * stock as it is now when the receipt is the same one, and is refused otherwise. The unique index of receipt keys
     * catches a key received into two variants at the same time (see StockItemRepository::save()).
     *
     * @throws InvalidReceiptKey
     * @throws InvalidStockNote
     * @throws VariantNotFound
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
        $variantId = VariantId::fromString($command->variantId);
        if (!$this->catalog->exists($variantId)) {
            throw new VariantNotFound();
        }

        $item = $this->stock->lockOrCreate($variantId);

        $receipt = $this->stock->findReceipt($key);
        if (null !== $receipt) {
            if (!$receipt->isReceiptOf($variantId, $command->quantity, $note)) {
                throw new ReceiptKeyReused();
            }

            return new ReceiveStockResult(StockView::of($item), true);
        }

        $item->receive($command->quantity, $key, $note, $origin);
        $this->stock->save($item);

        return new ReceiveStockResult(StockView::of($item), false);
    }
}

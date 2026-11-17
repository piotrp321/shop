<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Catalog;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Application\Event\ProductCreated;
use App\Inventory\Application\StockRecords\StockRecordSync;
use App\Inventory\Domain\Stock\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * After a product is created or changed, every variant it has gets a stock record with the product, and the empty
 * records of variants it no longer has go (decision A9 of ADMIN-FEATURES). The handler uses only the id of the event:
 * the variants are read from Catalog now, so a repeated or reordered event ends as the last one. A product Catalog
 * does not have is a handled message without a change.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class SyncStockRecordsOnProductChange
{
    public function __construct(private StockRecordSync $records)
    {
    }

    /**
     * @throws UnrecoverableMessageHandlingException when the event does not name a product
     */
    public function __invoke(ProductCreated|ProductChanged $event): void
    {
        try {
            $productId = ProductId::fromString($event->productId);
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException(\sprintf('%s does not name a product.', $event::class), previous: $e);
        }

        $this->records->ofProducts([$productId]);
    }
}

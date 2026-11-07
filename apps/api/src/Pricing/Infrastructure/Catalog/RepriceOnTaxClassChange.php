<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Catalog;

use App\Catalog\Application\Event\ProductChanged;
use App\Pricing\Application\TaxReconciliation\RepriceProductPrices;
use App\Pricing\Application\TaxReconciliation\TaxClassChangedWhileRepricing;
use App\Pricing\Application\TaxReconciliation\TaxDiscrepancyOutcome;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * An edited product may have a new tax class (decision K6): its prices are brought to the class the product has in
 * Catalog now by RepriceProductPrices. The event only names the product, so the handling is idempotent and the order
 * of deliveries does not matter; a product Catalog does not know is handled without changes.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class RepriceOnTaxClassChange
{
    public function __construct(private RepriceProductPrices $reprice)
    {
    }

    /**
     * @throws UnrecoverableMessageHandlingException the class of the product has no VAT rate, or the event does not
     *                                               name a product; the message goes to `failed`
     * @throws PriceModifiedConcurrently             a save was refused twice; the other prices are saved and
     *                                               Messenger retries the message
     * @throws TaxClassChangedWhileRepricing         Messenger retries the message
     */
    public function __invoke(ProductChanged $event): void
    {
        try {
            $productId = ProductId::fromString($event->productId);
        } catch (\InvalidArgumentException $e) {
            // Catalog publishes only what it accepted, so a retry cannot fix this.
            throw new UnrecoverableMessageHandlingException('ProductChanged does not name a product.', previous: $e);
        }

        $conflict = false;
        foreach (($this->reprice)($productId) as $discrepancy) {
            if (TaxDiscrepancyOutcome::NoRate === $discrepancy->outcome) {
                // A rate comes with a deployment (PolishVatRates), not with a retry.
                throw new UnrecoverableMessageHandlingException(\sprintf('The tax class %s of product %s has no VAT rate; its prices keep their class.', $discrepancy->expectedClass->toString(), $productId->toString()));
            }
            $conflict = $conflict || TaxDiscrepancyOutcome::ModifiedConcurrently === $discrepancy->outcome;
        }
        if ($conflict) {
            throw new PriceModifiedConcurrently();
        }
    }
}

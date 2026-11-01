<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\ProductTaxClasses;
use App\Pricing\Application\Query\ListedPrice;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\GrossPriceOutOfRange;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductNotFound;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRates;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SetProductPriceHandler
{
    public function __construct(
        private ProductPriceRepository $prices,
        private ProductTaxClasses $taxClasses,
        private VatRates $rates,
    ) {
    }

    /**
     * A first price takes the product's tax class from Catalog. An existing price keeps its own class and takes
     * its current rate, so the change also fixes an outdated rate. The same gross at the same rate changes nothing.
     *
     * @return ListedPrice the price as saved (with the version it was written with), or as stored when nothing changed
     *
     * @throws GrossPriceOutOfRange
     * @throws ProductNotFound
     * @throws PriceModifiedConcurrently when the expected version is not the stored one (null for an existing
     *                                   price, any version for a product without price), checked again on write
     * @throws TaxClassWithoutRate
     */
    public function __invoke(SetProductPrice $command): ListedPrice
    {
        $gross = GrossPrice::pln($command->gross);
        $origin = $this->origin($command);
        $productId = ProductId::fromString($command->productId);

        $taxClass = $this->taxClasses->taxClassOf($productId) ?? throw new ProductNotFound();

        $price = $this->prices->find($productId);
        if (null === $price) {
            if (null !== $command->expectedVersion) {
                throw new PriceModifiedConcurrently();
            }
            $price = ProductPrice::create($productId, $gross, $taxClass, $this->rates, $origin);
        } else {
            if ($command->expectedVersion !== $price->version()) {
                throw new PriceModifiedConcurrently();
            }
            $price->changeGross($gross, $this->rates, $origin);
        }

        $this->prices->save($price);

        return ListedPrice::of($price, $this->rates);
    }

    private function origin(SetProductPrice $command): PriceChangeOrigin
    {
        return match ($command->source) {
            PriceChangeSource::Staff => PriceChangeOrigin::staff($command->actorId ?? '', $command->actorName ?? ''),
            PriceChangeSource::Seed => PriceChangeOrigin::seed(),
            PriceChangeSource::TaxReconciliation => throw new \LogicException('A tax reconciliation never sets the gross.'),
        };
    }
}

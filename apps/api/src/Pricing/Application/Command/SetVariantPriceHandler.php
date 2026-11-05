<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\VariantTaxClasses;
use App\Pricing\Application\Query\ListedPrice;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\GrossPriceOutOfRange;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantNotFound;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRates;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SetVariantPriceHandler
{
    public function __construct(
        private VariantPriceRepository $prices,
        private VariantTaxClasses $taxClasses,
        private VatRates $rates,
    ) {
    }

    /**
     * A first price takes the tax class of the variant's product from Catalog. An existing price keeps its own class
     * and takes its current rate, so the change also fixes an outdated rate. The same gross at the same rate changes
     * nothing.
     *
     * @return ListedPrice the price as saved (with the version it was written with), or as stored when nothing changed
     *
     * @throws GrossPriceOutOfRange
     * @throws VariantNotFound
     * @throws PriceModifiedConcurrently when the expected version is not the stored one (null for an existing
     *                                   price, any version for a variant without price), checked again on write
     * @throws TaxClassWithoutRate
     */
    public function __invoke(SetVariantPrice $command): ListedPrice
    {
        $gross = GrossPrice::pln($command->gross);
        $origin = $this->origin($command);
        $variantId = VariantId::fromString($command->variantId);

        $taxClass = $this->taxClasses->taxClassOf($variantId) ?? throw new VariantNotFound();

        $price = $this->prices->find($variantId);
        if (null === $price) {
            if (null !== $command->expectedVersion) {
                throw new PriceModifiedConcurrently();
            }
            $price = VariantPrice::create($variantId, $gross, $taxClass, $this->rates, $origin);
        } else {
            if ($command->expectedVersion !== $price->version()) {
                throw new PriceModifiedConcurrently();
            }
            $price->changeGross($gross, $this->rates, $origin);
        }

        $this->prices->save($price);

        return ListedPrice::of($price, $this->rates);
    }

    private function origin(SetVariantPrice $command): PriceChangeOrigin
    {
        return match ($command->source) {
            PriceChangeSource::Staff => PriceChangeOrigin::staff($command->actorId ?? '', $command->actorName ?? ''),
            PriceChangeSource::Seed => PriceChangeOrigin::seed(),
            PriceChangeSource::TaxReconciliation => throw new \LogicException('A tax reconciliation never sets the gross.'),
        };
    }
}

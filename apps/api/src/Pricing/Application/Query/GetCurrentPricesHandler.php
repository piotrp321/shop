<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\VatRates;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCurrentPricesHandler
{
    public function __construct(
        private VariantPriceRepository $prices,
        private VatRates $rates,
    ) {
    }

    /**
     * One read for all the variants. A variant without a price, or with a price at a rate that is no longer the rate
     * of its class (see VariantPrice::isCurrent), has no snapshot: it cannot be bought until the price is repaired.
     * The snapshots follow the order of the request, a repeated id answers once.
     *
     * @return list<PriceSnapshot>
     *
     * @throws InvalidCurrentPricesQuery
     */
    public function __invoke(GetCurrentPrices $query): array
    {
        $count = \count($query->variantIds);
        if ($count < 1 || $count > GetCurrentPrices::MAX_VARIANTS) {
            throw InvalidCurrentPricesQuery::variantCountOutOfRange();
        }

        $ids = [];
        foreach ($query->variantIds as $raw) {
            try {
                $id = VariantId::fromString($raw);
            } catch (\InvalidArgumentException) {
                throw InvalidCurrentPricesQuery::notAVariantId();
            }
            $ids[$id->toString()] = $id;
        }

        $stored = [];
        foreach ($this->prices->findMany(array_values($ids)) as $price) {
            if ($price->isCurrent($this->rates)) {
                $stored[$price->id()->toString()] = $price;
            }
        }

        $snapshots = [];
        foreach (array_keys($ids) as $key) {
            $price = $stored[$key] ?? null;
            if (null === $price) {
                continue;
            }
            $breakdown = $price->breakdown();
            $snapshots[] = new PriceSnapshot(
                $key,
                $price->taxClass()->toString(),
                $breakdown->rate()->basisPoints(),
                $breakdown->net()->amount(),
                $breakdown->vat()->amount(),
                $breakdown->gross()->amount(),
                $breakdown->currency()->value,
                $price->version(),
            );
        }

        return $snapshots;
    }
}

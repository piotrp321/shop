<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\ActivePromotion;
use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\OmnibusHistory;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\LowestPriorPrice;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCurrentPricesHandler
{
    public function __construct(
        private CurrentEffectivePrices $effectivePrices,
        private OmnibusHistory $omnibus,
        private Clock $clock,
    ) {
    }

    /**
     * The effective prices by CurrentEffectivePrices (one read for the prices, one for the active promotions); with
     * the flag and a variant in a promotion, one read each of the history and the earlier promotions. A variant
     * without an effective price (no catalog price, or one at a rate that is no longer the rate of its class, see
     * EffectivePrice) has no snapshot: it cannot be bought until the price is repaired. The snapshots follow the
     * order of the request, a repeated id answers once.
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

        $current = $this->effectivePrices->at(array_values($ids), $this->clock->now());
        $active = array_filter(array_map(static fn (CurrentEffectivePrice $price): ?ActivePromotion => $price->promotion, $current));
        $lowestPrior = $query->withLowestPriorPrice ? $this->lowestPriorPrices($active) : [];

        $snapshots = [];
        foreach ($current as $key => $price) {
            $promotion = $price->promotion;
            $breakdown = $price->effective->breakdown();
            $snapshots[] = new PriceSnapshot(
                $key,
                $price->catalog->taxClass()->toString(),
                $breakdown->rate()->basisPoints(),
                $breakdown->net()->amount(),
                $breakdown->vat()->amount(),
                $breakdown->gross()->amount(),
                $breakdown->currency()->value,
                $price->catalog->version(),
                $price->effective->regularGross()->amount(),
                null === $promotion ? null : new PricePromotion(
                    $promotion->promotionId,
                    $promotion->name,
                    $promotion->percentOff->value(),
                    $promotion->period->endsAt(),
                    $lowestPrior[$key] ?? null,
                ),
            );
        }

        return $snapshots;
    }

    /**
     * @param array<string, ActivePromotion> $active by variant id
     *
     * @return array<string, int|null> the Omnibus gross by variant id
     */
    private function lowestPriorPrices(array $active): array
    {
        if ([] === $active) {
            return [];
        }
        $starts = array_map(static fn (ActivePromotion $promotion): \DateTimeImmutable => $promotion->period->startsAt(), $active);
        $history = $this->omnibus->prices($starts);
        $promotions = $this->omnibus->promotions($starts);

        $lowest = [];
        foreach ($starts as $variantId => $start) {
            $lowest[$variantId] = LowestPriorPrice::of($start, $history[$variantId] ?? [], $promotions[$variantId] ?? [])?->amount();
        }

        return $lowest;
    }
}

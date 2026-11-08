<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\ActivePromotion;
use App\Pricing\Application\Port\ActivePromotions;
use App\Pricing\Application\Port\OmnibusHistory;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Promotion\LowestPriorPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PriorPrice;
use App\Pricing\Domain\Promotion\PriorPromotion;
use App\Pricing\Domain\Promotion\PromotionPeriod;

/**
 * Stored promotions and price history as the read ports of the effective price and the Omnibus price see them, with
 * the calls each read got.
 */
final class InMemoryPromotionPrices implements ActivePromotions, OmnibusHistory
{
    /** @var list<array{promotion: ActivePromotion, variantIds: list<string>}> */
    private array $promotions = [];
    /** @var array<string, list<PriorPrice>> by variant id, oldest first */
    private array $history = [];
    /** @var list<array{list<string>, \DateTimeImmutable}> the ids and the moment of every at() call */
    public array $activeReads = [];
    /** @var list<list<string>> the variant ids of every prices() call */
    public array $priceReads = [];
    /** @var list<list<string>> the variant ids of every promotions() call */
    public array $promotionReads = [];

    /**
     * @param list<string> $variantIds
     */
    public function promotion(string $id, string $name, int $percentOff, string $startsAt, string $endsAt, array $variantIds): self
    {
        $this->promotions[] = [
            'promotion' => new ActivePromotion($id, $name, PercentOff::of($percentOff), PromotionPeriod::of(new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt))),
            'variantIds' => $variantIds,
        ];

        return $this;
    }

    public function price(string $variantId, string $changedAt, int $gross): self
    {
        $this->history[$variantId][] = new PriorPrice(new \DateTimeImmutable($changedAt), GrossPrice::pln($gross));

        return $this;
    }

    public function at(array $variantIds, \DateTimeImmutable $moment): array
    {
        $keys = array_map(static fn ($id): string => $id->toString(), $variantIds);
        $this->activeReads[] = [$keys, $moment];
        $active = [];
        foreach ($this->promotions as ['promotion' => $promotion, 'variantIds' => $ids]) {
            if (!$promotion->period->isActiveAt($moment)) {
                continue;
            }
            foreach (array_intersect($keys, $ids) as $key) {
                $active[$key] = $promotion;
            }
        }

        return $active;
    }

    public function prices(array $promotionStarts): array
    {
        $this->priceReads[] = array_keys($promotionStarts);
        $prices = [];
        foreach ($promotionStarts as $variantId => $start) {
            $windowStart = LowestPriorPrice::windowStart($start);
            $before = null;
            $inWindow = [];
            foreach ($this->history[$variantId] ?? [] as $entry) {
                if ($entry->changedAt < $windowStart) {
                    $before = $entry;
                } elseif ($entry->changedAt < $start) {
                    $inWindow[] = $entry;
                }
            }
            $entries = null === $before ? $inWindow : [$before, ...$inWindow];
            if ([] !== $entries) {
                $prices[$variantId] = $entries;
            }
        }

        return $prices;
    }

    public function promotions(array $promotionStarts): array
    {
        $this->promotionReads[] = array_keys($promotionStarts);
        $prior = [];
        foreach ($promotionStarts as $variantId => $start) {
            $window = PromotionPeriod::of(LowestPriorPrice::windowStart($start), $start);
            foreach ($this->promotions as ['promotion' => $promotion, 'variantIds' => $ids]) {
                if (\in_array($variantId, $ids, true) && $promotion->period->overlaps($window)) {
                    $prior[$variantId][] = new PriorPromotion($promotion->period, $promotion->percentOff);
                }
            }
        }

        return $prior;
    }
}

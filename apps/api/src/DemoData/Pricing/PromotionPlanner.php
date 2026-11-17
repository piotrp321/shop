<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Catalog\ProductSpec;
use App\DemoData\Catalog\ProductType;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Random\Prng;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionPeriod;

/**
 * The ended promotions of the generated catalog (spec: "Realizm", "Ceny"; P8: none is active, so no boundary message is
 * needed). A promotion is a department's sale of 3 to 30 days at 10 to 50 percent, a sample of the variants of that
 * department among the products of a window of one to three parts of the catalog, published at least two days before
 * the start. Every promotion has at most 1000 variants, and a variant never joins a promotion that overlaps one it is
 * in. Every eighth promotion is recent (the last 100 days before the anchor); the rest are spread over the three years
 * of orders.
 *
 * A pure function of the profile, the seed and the anchor: the plan costs the generation of a few dozen parts of the
 * catalog, so every process can compute it. A promotion that finds no variant is skipped; its index stays unused, so the
 * ids (UUID v5 `promotion:<index>`) of the others do not change.
 */
final class PromotionPlanner
{
    public const string STREAM = 'promotion';
    public const int COUNT = 40;
    private const int HISTORY_DAYS = 1_095;
    private const int RECENT_DAYS = 100;
    private const int RECENT_EVERY = 8;
    private const int DAY_SECONDS = 86_400;
    /** The products of a promotion are published this long before it starts. */
    private const int PUBLISHED_BEFORE_DAYS = 2;
    private const int CREATED_BEFORE_DAYS = 3;
    private const int WINDOW_PARTS = 3;
    private const array DURATIONS_DAYS = [3, 5, 7, 7, 10, 10, 14, 14, 21, 30];
    private const array PERCENTS = [10, 15, 20, 25, 30, 40, 50];
    private const array PERCENT_WEIGHTS = [3, 4, 5, 3, 3, 2, 1];
    private const array SIZES = [30, 60, 120, 250, 500, 1_000];
    private const array SIZE_WEIGHTS = [3, 4, 4, 3, 2, 1];

    /** @var array<string, ProductType> */
    private array $types = [];
    /** @var array<string, string> the name of a department by its slug */
    private array $departments = [];

    public function __construct(
        Dictionaries $dictionaries,
        private readonly ProductGenerator $products,
        private readonly string $namespace,
        private readonly \DateTimeImmutable $anchor,
        private readonly int $total,
        private readonly int $partSize,
    ) {
        foreach ($dictionaries->types() as $type) {
            $this->types[$type->key] = $type;
        }
        foreach ($dictionaries->departments() as $department) {
            $this->departments[$department['slug']] = $department['name'];
        }
    }

    /**
     * @return list<PromotionSpec> in the order of their index
     */
    public function plan(): array
    {
        $promotions = [];
        /** @var array<string, list<PromotionPeriod>> $taken the periods of the promotions a variant is in */
        $taken = [];
        for ($index = 0; $index < self::COUNT; ++$index) {
            $promotion = $this->promotion($index, $taken);
            if (null === $promotion) {
                continue;
            }
            foreach ($promotion->variantIds as $variantId) {
                $taken[$variantId][] = $promotion->period;
            }
            $promotions[] = $promotion;
        }

        return $promotions;
    }

    /**
     * The promotions of every promoted variant, by the id of the variant, in the order of their start.
     *
     * @param list<PromotionSpec> $promotions
     *
     * @return array<string, list<PromotionSpec>>
     */
    public static function byVariant(array $promotions): array
    {
        $byVariant = [];
        foreach ($promotions as $promotion) {
            foreach ($promotion->variantIds as $variantId) {
                $byVariant[$variantId][] = $promotion;
            }
        }
        foreach ($byVariant as &$list) {
            usort($list, static fn (PromotionSpec $a, PromotionSpec $b): int => $a->period->startsAt() <=> $b->period->startsAt());
        }

        return $byVariant;
    }

    /**
     * @param array<string, list<PromotionPeriod>> $taken
     */
    private function promotion(int $index, array $taken): ?PromotionSpec
    {
        $prng = Prng::forPart($this->namespace, self::STREAM, $index);

        $days = $prng->pick(self::DURATIONS_DAYS);
        $today = intdiv($this->anchor->getTimestamp(), self::DAY_SECONDS) * self::DAY_SECONDS;
        // The promotion has ended a day before the anchor at the latest.
        $earliestBefore = $days + 1;
        $latestBefore = 0 === ($index + 1) % self::RECENT_EVERY ? self::RECENT_DAYS : self::HISTORY_DAYS - 5;
        $startsAt = $today - $prng->int($earliestBefore, max($earliestBefore, $latestBefore)) * self::DAY_SECONDS;
        $period = PromotionPeriod::of(new \DateTimeImmutable('@'.$startsAt), new \DateTimeImmutable('@'.($startsAt + $days * self::DAY_SECONDS)));
        $percent = self::PERCENTS[$prng->weighted(self::PERCENT_WEIGHTS)];
        $target = self::SIZES[$prng->weighted(self::SIZE_WEIGHTS)];

        $candidates = $this->candidates($period, $prng);
        if ([] === $candidates) {
            return null;
        }
        $department = $prng->pick(array_keys($candidates));
        $variantIds = $this->variants($candidates[$department], $target, $period, $taken, $prng);
        if ([] === $variantIds) {
            return null;
        }

        return new PromotionSpec(
            DemoUuid::v5($this->namespace, 'promotion:'.$index),
            \sprintf('Promocja %s -%d%% (%s)', $this->departments[$department] ?? $department, $percent, $period->startsAt()->format('m.Y')),
            PercentOff::of($percent),
            $period,
            $period->startsAt()->modify(\sprintf('-%d days', self::CREATED_BEFORE_DAYS)),
            $variantIds,
        );
    }

    /**
     * The variants a promotion of the period may take, by department: those of the published products of a window of
     * parts, published long enough before the start. The window starts at a part that has such products.
     *
     * @return array<string, list<string>> variant ids by the slug of the department, the departments in a fixed order
     */
    private function candidates(PromotionPeriod $period, Prng $prng): array
    {
        $parts = intdiv($this->total + $this->partSize - 1, $this->partSize);
        $published = $this->products->publishedBy($period->startsAt()->modify(\sprintf('-%d days', self::PUBLISHED_BEFORE_DAYS)));
        if ($published < 1 || $parts < 1) {
            return [];
        }
        $first = $prng->int(0, intdiv($published - 1, $this->partSize));
        $last = min($parts, $first + 1 + $prng->int(0, self::WINDOW_PARTS - 1));
        $limit = $period->startsAt()->modify(\sprintf('-%d days', self::PUBLISHED_BEFORE_DAYS));

        $byDepartment = [];
        for ($part = $first; $part < $last; ++$part) {
            foreach ($this->products->part($part) as $product) {
                if (!$this->isPromotable($product, $limit)) {
                    continue;
                }
                foreach ($product->variants as $variant) {
                    $byDepartment[$this->types[$product->typeKey]->department()][] = $variant->id;
                }
            }
        }
        ksort($byDepartment);

        return $byDepartment;
    }

    private function isPromotable(ProductSpec $product, \DateTimeImmutable $limit): bool
    {
        return null !== $product->firstPublishedAt && $product->firstPublishedAt <= $limit;
    }

    /**
     * A sample of about `target` of the candidates, without the variants that are in an overlapping promotion; at least
     * one when any is free, at most the largest promotion.
     *
     * @param list<string>                         $candidates
     * @param array<string, list<PromotionPeriod>> $taken
     *
     * @return list<string>
     */
    private function variants(array $candidates, int $target, PromotionPeriod $period, array $taken, Prng $prng): array
    {
        $free = array_values(array_filter($candidates, static function (string $variantId) use ($taken, $period): bool {
            foreach ($taken[$variantId] ?? [] as $other) {
                if ($period->overlaps($other)) {
                    return false;
                }
            }

            return true;
        }));
        if ([] === $free) {
            return [];
        }

        $share = min(1.0, $target / \count($free));
        $chosen = array_values(array_filter($free, static fn (string $variantId): bool => $prng->chance($share)));
        if ([] === $chosen) {
            $chosen = [$prng->pick($free)];
        }

        return \array_slice($chosen, 0, Promotion::MAX_VARIANTS);
    }
}

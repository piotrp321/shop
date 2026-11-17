<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductSpec;
use App\DemoData\Catalog\ProductType;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Identity\StaffRoles;
use App\DemoData\Random\Prng;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Domain\Tax\VatRates;

/**
 * The prices and the price history of the variants of one product (spec: "Realizm", "Ceny"). A pure function of the
 * product, the seed and the anchor: every product draws from a stream of its own (`pricing`, product index), so the
 * prices do not depend on the other products, the part or the number of processes, and orders can ask for the timeline
 * of a single variant.
 *
 * The price of a product is a rung of the ladder of its type (cheaper rungs are likelier) with the ending `,99`
 * (three products in four) or `,00`; the variants share it, or, for a third of the products with a size, ask a rung
 * more for every three sizes up. Net and VAT come from PriceBreakdown at the rate of the tax class, the source and the
 * actor of an entry from PriceChangeOrigin. The history starts with an entry of the source `seed` an hour before the
 * product is published (a draft: before its last change), so every possible order has a price. About 30% of the
 * variants have one change by an `admin` of the staff (source `staff`) in the last 90 days: a rung or two up or down,
 * never inside a promotion of the variant (the price of a promoted variant is locked, as in the application).
 */
final class PriceGenerator
{
    public const string STREAM = 'pricing';
    public const float CHANGE_SHARE = 0.30;
    public const int CHANGE_WINDOW_DAYS = 90;
    private const float ENDING_99_SHARE = 0.75;
    private const float SURCHARGE_SHARE = 0.35;
    private const int SIZES_PER_RUNG = 3;
    private const string SIZE_AXIS = 'Rozmiar';
    /** The first entry is this long before the product is published. */
    private const int INITIAL_LEAD_SECONDS = 3_600;
    /** A change is at least this long after the first entry and this long before the anchor. */
    private const int CHANGE_MARGIN_SECONDS = 7_200;
    private const int DAY_SECONDS = 86_400;
    /** The steps of a change in rungs: two down, one down, one up, two up. */
    private const array CHANGE_STEPS = [-2, -1, 1, 2];

    /** @var array<string, ProductType> */
    private array $types = [];
    /** @var list<int> */
    private array $admins;
    private VatRates $rates;
    private int $anchor;

    public function __construct(
        Dictionaries $dictionaries,
        private readonly string $namespace,
        \DateTimeImmutable $anchor,
        int $staff,
    ) {
        foreach ($dictionaries->types() as $type) {
            $this->types[$type->key] = $type;
        }
        $this->admins = StaffRoles::adminNumbers($staff);
        $this->rates = new PolishVatRates();
        $this->anchor = $anchor->getTimestamp();
    }

    /**
     * @param array<string, list<PromotionSpec>> $promotionsByVariant the promotions of the variants by the id of the variant
     *
     * @return list<PriceTimeline> one per variant of the product, in its order
     */
    public function forProduct(ProductSpec $product, array $promotionsByVariant = []): array
    {
        $type = $this->types[$product->typeKey] ?? throw new \InvalidArgumentException(\sprintf('Unknown product type "%s".', $product->typeKey));
        $prng = Prng::forPart($this->namespace, self::STREAM, $product->index);

        $rungs = PriceLadder::rungs($type->priceMin, $type->priceMax);
        $last = \count($rungs) - 1;
        $base = min($prng->int(0, $last), $prng->int(0, $last));
        $ninetyNine = $prng->chance(self::ENDING_99_SHARE);
        $surcharge = $prng->chance(self::SURCHARGE_SHARE);
        $sizeAxis = array_search(self::SIZE_AXIS, $product->axes, true);

        $initialAt = ($product->firstPublishedAt ?? $product->updatedAt)->getTimestamp() - self::INITIAL_LEAD_SECONDS;
        $rate = $this->rates->rateFor(TaxClass::fromString($product->taxClass));

        $timelines = [];
        foreach ($product->variants as $variant) {
            $rung = $base;
            if ($surcharge && false !== $sizeAxis) {
                $size = array_search($variant->values[$sizeAxis] ?? null, $type->axes[$sizeAxis]->values ?? [], true);
                $rung = min($last, $base + intdiv(false === $size ? 0 : $size, self::SIZES_PER_RUNG));
            }

            $entries = [$this->entry($variant->id, $initialAt, PriceLadder::gross($rungs[$rung], $ninetyNine), $product->taxClass, $rate, PriceChangeOrigin::seed(), $prng)];

            // The draws of a change are made for every variant, so the stream does not depend on whether it happens.
            $changes = $prng->chance(self::CHANGE_SHARE);
            $offset = $prng->int(0, self::DAY_SECONDS * self::CHANGE_WINDOW_DAYS);
            $step = self::CHANGE_STEPS[$prng->int(0, \count(self::CHANGE_STEPS) - 1)];
            $admin = [] === $this->admins ? null : $prng->pick($this->admins);

            if ($changes && null !== $admin) {
                $at = $this->changeInstant($initialAt, $offset, $promotionsByVariant[$variant->id] ?? []);
                if (null !== $at) {
                    $target = max(0, min($last, $rung + $step));
                    if ($target === $rung) {
                        $target = max(0, min($last, $rung - $step));
                    }
                    // A ladder with one rung has nothing to move to: the ending changes instead.
                    $endsWithNinetyNine = $target === $rung ? !$ninetyNine : $ninetyNine;
                    $entries[] = $this->entry(
                        $variant->id,
                        $at,
                        PriceLadder::gross($rungs[$target], $endsWithNinetyNine),
                        $product->taxClass,
                        $rate,
                        PriceChangeOrigin::staff(StaffRoles::actorId($this->namespace, $admin), StaffRoles::actorName($admin)),
                        $prng,
                    );
                }
            }

            $timelines[] = new PriceTimeline($entries, $promotionsByVariant[$variant->id] ?? []);
        }

        return $timelines;
    }

    /**
     * The instant of the change of a variant: a moment of the last 90 days that is after the first entry, and after any
     * promotion of the variant running then (the price waits for its end). Null when no such moment is left before the
     * anchor.
     *
     * @param list<PromotionSpec> $promotions
     *
     * @return int|null a Unix timestamp
     */
    private function changeInstant(int $initialAt, int $offset, array $promotions): ?int
    {
        $from = max($this->anchor - self::CHANGE_WINDOW_DAYS * self::DAY_SECONDS, $initialAt + self::CHANGE_MARGIN_SECONDS);
        $until = $this->anchor - self::INITIAL_LEAD_SECONDS;
        if ($from >= $until) {
            return null;
        }
        $at = $from + intdiv($offset * ($until - $from), self::DAY_SECONDS * self::CHANGE_WINDOW_DAYS);

        do {
            $moved = false;
            foreach ($promotions as $promotion) {
                if ($promotion->period->isActiveAt(new \DateTimeImmutable('@'.$at))) {
                    $at = $promotion->period->endsAt()->getTimestamp();
                    $moved = true;
                }
            }
        } while ($moved);

        return $at < $until ? $at : null;
    }

    private function entry(string $variantId, int $at, int $gross, string $taxClass, VatRate $rate, PriceChangeOrigin $origin, Prng $prng): PriceEntry
    {
        $changedAt = new \DateTimeImmutable('@'.$at);
        $breakdown = PriceBreakdown::fromGross(GrossPrice::pln($gross), $rate);

        return new PriceEntry(
            DemoUuid::v7($changedAt, $prng),
            $changedAt,
            $breakdown->gross()->amount(),
            $breakdown->net()->amount(),
            $breakdown->vat()->amount(),
            $breakdown->rate()->basisPoints(),
            $taxClass,
            $origin->source()->value,
            $origin->actorId(),
            $origin->actorName(),
        );
    }
}

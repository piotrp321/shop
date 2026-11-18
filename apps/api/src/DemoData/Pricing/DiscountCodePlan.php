<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\DemoData\Identity\DemoUuid;
use App\DemoData\Random\Prng;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;

/**
 * The historical discount codes of the generated orders (spec: "Realizm", "Zamówienia"): a dozen and a half codes
 * named after a season or a campaign and its year (`ZIMA-24`), one after another over the three years before the anchor,
 * each valid for two to six weeks and ended (P8: none is active). Every third code takes an amount off with a minimum
 * five times higher, the others a percent; every fourth has a usage limit. The ids are UUID v5 `code:<n>` from 1. The
 * codes of the seed (`fixtures/discount-codes.yaml`) are not touched: no text here is theirs. A pure function of the
 * seed and the anchor.
 */
final class DiscountCodePlan
{
    public const string STREAM = 'code';
    private const int HISTORY_DAYS = 1_095;
    private const int DAY_SECONDS = 86_400;
    private const int CREATED_BEFORE_DAYS = 7;
    /** The last code ends this long before the anchor at the latest. */
    private const int ENDED_BEFORE_DAYS = 2;
    private const array NAMES = ['WIOSNA', 'MAJOWKA', 'WAKACJE', 'LATO', 'SZKOLA', 'JESIEN', 'BLACKWEEK', 'MIKOLAJ', 'ZIMA', 'WALENTYNKI', 'POWROT', 'START', 'FINAL', 'NOWYROK'];
    private const array PERCENTS = [5, 10, 15, 20];
    private const array AMOUNTS_ZLOTYS = [20, 30, 50, 100];

    public function __construct(
        private readonly string $namespace,
        private readonly \DateTimeImmutable $anchor,
    ) {
    }

    /**
     * @return list<DiscountCodeSpec> in the order of their start
     */
    public function codes(): array
    {
        $today = intdiv($this->anchor->getTimestamp(), self::DAY_SECONDS) * self::DAY_SECONDS;
        $slotDays = intdiv(self::HISTORY_DAYS, \count(self::NAMES));

        $codes = [];
        foreach (self::NAMES as $index => $name) {
            $prng = Prng::forPart($this->namespace, self::STREAM, $index);
            $days = $prng->int(14, 42);
            // The slot of the code: a code starts in the first two thirds of its slot and ends with the slot at the latest.
            $slotStart = $today - (self::HISTORY_DAYS - $index * $slotDays) * self::DAY_SECONDS;
            $startsAt = $slotStart + $prng->int(0, intdiv($slotDays * 2, 3)) * self::DAY_SECONDS;
            $endsAt = min($startsAt + $days * self::DAY_SECONDS, $today - self::ENDED_BEFORE_DAYS * self::DAY_SECONDS);
            $period = ValidityPeriod::of(new \DateTimeImmutable('@'.$startsAt), new \DateTimeImmutable('@'.$endsAt));

            $amount = 2 === $index % 3;
            $value = $amount
                ? DiscountValue::of(DiscountKind::Amount, $prng->pick(self::AMOUNTS_ZLOTYS) * 100)
                : DiscountValue::of(DiscountKind::Percent, $prng->pick(self::PERCENTS));
            $minimum = $amount ? MinimumOrder::of($value->value() * 5) : null;

            $codes[] = new DiscountCodeSpec(
                DemoUuid::v5($this->namespace, 'code:'.($index + 1)),
                DiscountCodeValue::of($name.'-'.$period->startsAt()->format('y')),
                $value,
                $minimum,
                $period,
                0 === $index % 4 ? UsageLimit::of(2_000) : null,
                true,
                $period->startsAt()->modify(\sprintf('-%d days', self::CREATED_BEFORE_DAYS)),
            );
        }

        return $codes;
    }
}

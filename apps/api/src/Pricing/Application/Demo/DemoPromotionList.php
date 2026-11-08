<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Domain\Promotion\InvalidPromotion;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionPeriod;

/**
 * The demo promotions in the order of the file. A variant belongs to the first promotion whose patterns match its key
 * (see DemoPromotionSeeder), so a specific promotion goes before a general one.
 */
final readonly class DemoPromotionList
{
    /** @var list<DemoPromotion> */
    private array $promotions;

    /**
     * @param list<array{name: string, percentOff: int, days: int, patterns: list<string>}> $promotions in order
     *
     * @throws \InvalidArgumentException on an empty list, a name, percentage or length the Promotion would refuse, or a
     *                                   promotion without patterns or with a malformed pattern
     */
    public function __construct(array $promotions)
    {
        if ([] === $promotions) {
            throw new \InvalidArgumentException('The demo promotions must name at least one promotion.');
        }

        $list = [];
        foreach ($promotions as ['name' => $name, 'percentOff' => $percentOff, 'days' => $days, 'patterns' => $patterns]) {
            $name = trim($name);
            if ('' === $name || mb_strlen($name) > Promotion::MAX_NAME_LENGTH) {
                throw new \InvalidArgumentException(\sprintf('The demo promotion name "%s" must have 1 to %d characters.', $name, Promotion::MAX_NAME_LENGTH));
            }
            try {
                PercentOff::of($percentOff);
            } catch (InvalidPromotion $e) {
                throw new \InvalidArgumentException(\sprintf('The percentOff of the demo promotion "%s" is invalid: %s', $name, $e->getMessage()), 0, $e);
            }
            if ($days < 1 || $days > PromotionPeriod::MAX_DAYS) {
                throw new \InvalidArgumentException(\sprintf('The days of the demo promotion "%s" must be 1 to %d.', $name, PromotionPeriod::MAX_DAYS));
            }
            if ([] === $patterns) {
                throw new \InvalidArgumentException(\sprintf('The demo promotion "%s" must name at least one variant key pattern.', $name));
            }

            $list[] = new DemoPromotion($name, $percentOff, $days, array_map(static fn (string $pattern): VariantKeyPattern => new VariantKeyPattern($pattern), $patterns));
        }
        $this->promotions = $list;
    }

    /**
     * @return list<DemoPromotion>
     */
    public function promotions(): array
    {
        return $this->promotions;
    }
}

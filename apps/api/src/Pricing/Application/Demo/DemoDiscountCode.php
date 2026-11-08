<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Domain\DiscountCode\DiscountKind;

/**
 * One demo discount code. The period is relative to the moment of the seed: it starts `startsInDays` days from it
 * (a negative number is in the past) and ends `endsInDays` days from it, or never.
 */
final readonly class DemoDiscountCode
{
    /**
     * @param string   $code              the normalised text
     * @param int      $value             the percent, or the amount in grosze
     * @param int|null $minimumOrderGross in grosze
     */
    public function __construct(
        public string $code,
        public DiscountKind $kind,
        public int $value,
        public ?int $minimumOrderGross,
        public int $startsInDays,
        public ?int $endsInDays,
    ) {
    }
}

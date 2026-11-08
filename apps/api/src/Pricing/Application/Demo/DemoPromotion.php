<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

/**
 * One demo promotion: its percentage and length in days (it starts when the seed plans it) and the patterns of the
 * variant keys it covers.
 */
final readonly class DemoPromotion
{
    /**
     * @param list<VariantKeyPattern> $patterns at least one
     */
    public function __construct(
        public string $name,
        public int $percentOff,
        public int $days,
        public array $patterns,
    ) {
    }

    public function matches(string $key): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($key)) {
                return true;
            }
        }

        return false;
    }
}

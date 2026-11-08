<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\GrossPriceOutOfRange;

/**
 * Gross demo prices by pattern of variant keys (`<product slug>/<label slug>`, `<product slug>/standard` for the
 * default variant). Patterns are checked in order and the first that matches the whole key wins; `*` matches any run
 * of characters (also none, also `/`).
 */
final readonly class DemoPriceList
{
    /** @var list<array{pattern: VariantKeyPattern, gross: int}> */
    private array $rules;

    /**
     * @param list<array{pattern: string, gross: int}> $rules in order
     *
     * @throws \InvalidArgumentException on an empty list, a malformed pattern or a gross price out of range
     */
    public function __construct(array $rules)
    {
        if ([] === $rules) {
            throw new \InvalidArgumentException('The demo prices must name at least one variant key pattern.');
        }

        $compiled = [];
        foreach ($rules as ['pattern' => $pattern, 'gross' => $gross]) {
            $compiledPattern = new VariantKeyPattern($pattern);
            try {
                GrossPrice::pln($gross);
            } catch (GrossPriceOutOfRange $e) {
                throw new \InvalidArgumentException(\sprintf('The demo price of "%s" is invalid: %s', $pattern, $e->getMessage()), 0, $e);
            }

            $compiled[] = ['pattern' => $compiledPattern, 'gross' => $gross];
        }
        $this->rules = $compiled;
    }

    /**
     * @return int|null the gross price in grosze, null when no pattern matches
     */
    public function grossFor(string $key): ?int
    {
        foreach ($this->rules as ['pattern' => $pattern, 'gross' => $gross]) {
            if ($pattern->matches($key)) {
                return $gross;
            }
        }

        return null;
    }
}

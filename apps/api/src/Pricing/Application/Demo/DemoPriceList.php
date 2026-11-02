<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\GrossPriceOutOfRange;

/**
 * Gross demo prices by slug pattern. Patterns are checked in order and the first that matches the whole slug wins;
 * `*` matches any run of characters (also none).
 */
final readonly class DemoPriceList
{
    /** @var list<array{regex: string, gross: int}> */
    private array $rules;

    /**
     * @param list<array{pattern: string, gross: int}> $rules in order
     *
     * @throws \InvalidArgumentException on an empty list, a malformed pattern or a gross price out of range
     */
    public function __construct(array $rules)
    {
        if ([] === $rules) {
            throw new \InvalidArgumentException('The demo prices must name at least one slug pattern.');
        }

        $compiled = [];
        foreach ($rules as ['pattern' => $pattern, 'gross' => $gross]) {
            if (1 !== preg_match('/[a-z0-9]/', $pattern)) {
                throw new \InvalidArgumentException(\sprintf('The demo price pattern "%s" must contain a letter or digit.', $pattern));
            }
            if (1 !== preg_match('/^[a-z0-9*-]+$/D', $pattern)) {
                throw new \InvalidArgumentException(\sprintf('The demo price pattern "%s" may only contain a-z, 0-9, "-" and the wildcard "*".', $pattern));
            }
            try {
                GrossPrice::pln($gross);
            } catch (GrossPriceOutOfRange $e) {
                throw new \InvalidArgumentException(\sprintf('The demo price of "%s" is invalid: %s', $pattern, $e->getMessage()), 0, $e);
            }

            $compiled[] = ['regex' => '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/D', 'gross' => $gross];
        }
        $this->rules = $compiled;
    }

    /**
     * @return int|null the gross price in grosze, null when no pattern matches
     */
    public function grossFor(string $slug): ?int
    {
        foreach ($this->rules as ['regex' => $regex, 'gross' => $gross]) {
            if (1 === preg_match($regex, $slug)) {
                return $gross;
            }
        }

        return null;
    }
}

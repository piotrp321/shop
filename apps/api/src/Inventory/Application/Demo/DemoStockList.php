<?php

declare(strict_types=1);

namespace App\Inventory\Application\Demo;

use App\Inventory\Domain\Stock\StockItem;

/**
 * Demo stock (physical units) by pattern of variant keys (`<product slug>/<label slug>`, `<product slug>/standard` for
 * the default variant). Patterns are checked in order and the first that matches the whole key wins; `*` matches any
 * run of characters (also none, also `/`).
 */
final readonly class DemoStockList
{
    /** @var list<array{regex: string, quantity: int}> */
    private array $rules;

    /**
     * @param list<array{pattern: string, quantity: int}> $rules in order
     *
     * @throws \InvalidArgumentException on an empty list, a malformed pattern or a quantity a receipt cannot bring
     */
    public function __construct(array $rules)
    {
        if ([] === $rules) {
            throw new \InvalidArgumentException('The demo stock must name at least one variant key pattern.');
        }

        $compiled = [];
        foreach ($rules as ['pattern' => $pattern, 'quantity' => $quantity]) {
            if (1 !== preg_match('/[a-z0-9]/', $pattern)) {
                throw new \InvalidArgumentException(\sprintf('The demo stock pattern "%s" must contain a letter or digit.', $pattern));
            }
            if (1 !== preg_match('~^[a-z0-9*/-]+$~D', $pattern)) {
                throw new \InvalidArgumentException(\sprintf('The demo stock pattern "%s" may only contain a-z, 0-9, "-", "/" and the wildcard "*".', $pattern));
            }
            if ($quantity < 0 || $quantity > StockItem::MAX_RECEIPT) {
                throw new \InvalidArgumentException(\sprintf('The demo stock of "%s" must be between 0 and %d units.', $pattern, StockItem::MAX_RECEIPT));
            }

            $compiled[] = ['regex' => '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/D', 'quantity' => $quantity];
        }
        $this->rules = $compiled;
    }

    /**
     * @return int|null the physical stock, null when no pattern matches (0 is a stock: nothing on the shelf)
     */
    public function quantityFor(string $key): ?int
    {
        foreach ($this->rules as ['regex' => $regex, 'quantity' => $quantity]) {
            if (1 === preg_match($regex, $key)) {
                return $quantity;
            }
        }

        return null;
    }
}

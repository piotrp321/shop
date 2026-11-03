<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\CartPrices;
use App\Cart\Application\Port\ProductAvailability;
use App\Cart\Application\Port\ProductCatalog;
use App\Cart\Application\Port\ProductSummary;
use App\Cart\Application\Port\StockAvailability;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;

/**
 * Catalog, Pricing and Inventory as Cart's ports see them, with the calls each port got.
 */
final class InMemoryProducts implements ProductCatalog, CartPrices, StockAvailability
{
    /** @var array<string, string> */
    private array $names = [];
    /** @var array<string, int> */
    private array $prices = [];
    /** @var array<string, int> */
    private array $available = [];
    /** @var array{summaries: list<list<string>>, current: list<list<string>>, of: list<list<string>>} the ids of each call by method */
    private array $calls = ['summaries' => [], 'current' => [], 'of' => []];

    /**
     * A product Catalog knows, with a gross price (null: none) and the available units.
     */
    public function product(string $id, string $name = 'Produkt', ?int $gross = 1000, int $available = 100): self
    {
        $this->names[$id] = $name;
        if (null !== $gross) {
            $this->prices[$id] = $gross;
        } else {
            unset($this->prices[$id]);
        }
        $this->available[$id] = $available;

        return $this;
    }

    /**
     * @return array{summaries: list<list<string>>, current: list<list<string>>, of: list<list<string>>}
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function resetCalls(): void
    {
        $this->calls = ['summaries' => [], 'current' => [], 'of' => []];
    }

    public function forget(string $id): void
    {
        unset($this->names[$id]);
    }

    public function summaries(array $ids): array
    {
        $this->calls['summaries'][] = self::keys($ids);
        $summaries = [];
        foreach ($ids as $id) {
            if (isset($this->names[$id->toString()])) {
                $summaries[] = new ProductSummary($id, $this->names[$id->toString()], null);
            }
        }

        return $summaries;
    }

    public function current(array $ids): array
    {
        $this->calls['current'][] = self::keys($ids);
        $prices = [];
        foreach ($ids as $id) {
            if (isset($this->prices[$id->toString()])) {
                $prices[] = new CartPrice($id, Money::of($this->prices[$id->toString()], Currency::PLN));
            }
        }

        return $prices;
    }

    public function of(array $ids): array
    {
        return array_map(function (ProductId $id): ProductAvailability {
            return new ProductAvailability($id, $this->available[$id->toString()] ?? 0);
        }, $this->recordAvailabilityCall($ids));
    }

    /**
     * @param list<ProductId> $ids
     *
     * @return list<ProductId>
     */
    private function recordAvailabilityCall(array $ids): array
    {
        $this->calls['of'][] = self::keys($ids);

        return $ids;
    }

    /**
     * @param list<ProductId> $ids
     *
     * @return list<string>
     */
    private static function keys(array $ids): array
    {
        return array_map(static fn (ProductId $id): string => $id->toString(), $ids);
    }
}

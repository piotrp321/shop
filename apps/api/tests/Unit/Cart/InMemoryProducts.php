<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\CartPrices;
use App\Cart\Application\Port\ProductCatalog;
use App\Cart\Application\Port\ProductVariants;
use App\Cart\Application\Port\StockAvailability;
use App\Cart\Application\Port\VariantAvailability;
use App\Cart\Application\Port\VariantLabel;
use App\Cart\Application\Port\VariantSummary;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\StockLevel;
use App\Cart\Domain\VariantId;
use App\Cart\Domain\VariantPromotion;

/**
 * Catalog, Pricing and Inventory as Cart's ports see them, with the calls each port got.
 *
 * Every variant belongs to a product whose id differs from the id of the variant (productOf() unless one is given),
 * so a test that mixes them up fails.
 */
final class InMemoryProducts implements ProductCatalog, CartPrices, StockAvailability
{
    /** @var array<string, array{productId: string, name: string, label: string}> by variant id */
    private array $variants = [];
    /** @var array<string, list<string>> axes by product id, for the products that have them */
    private array $axes = [];
    /** @var array<string, int> */
    private array $prices = [];
    /** @var array<string, array{regularGross: int, promotion: VariantPromotion}> by variant id */
    private array $promotions = [];
    /** @var array<string, int> */
    private array $available = [];
    /** @var list<bool> the withLowestPriorPrice flag of every current() call */
    public array $lowestPriorPriceFlags = [];
    /** @var array{summaries: list<list<string>>, current: list<list<string>>, of: list<list<string>>} the ids of each call by method */
    private array $calls = ['summaries' => [], 'current' => [], 'of' => []];
    /** @var list<list<string>> */
    private array $variantsOfCalls = [];

    /**
     * A variant Catalog knows, with a gross price (null: none) and the available units.
     */
    public function variant(string $id, string $name = 'Produkt', ?int $gross = 1000, int $available = 100, ?string $productId = null, string $label = ''): self
    {
        $this->variants[$id] = ['productId' => $productId ?? self::productOf($id), 'name' => $name, 'label' => $label];
        if (null !== $gross) {
            $this->prices[$id] = $gross;
        } else {
            unset($this->prices[$id]);
        }
        $this->available[$id] = $available;

        return $this;
    }

    /**
     * Puts a variant with a price in a promotion: its gross (of variant()) becomes the promotional one, the catalog
     * gross is given.
     */
    public function promoted(string $id, int $regularGross, string $endsAt, ?int $lowestPriorGross): self
    {
        $this->promotions[$id] = [
            'regularGross' => $regularGross,
            'promotion' => new VariantPromotion(new \DateTimeImmutable($endsAt), null === $lowestPriorGross ? null : Money::of($lowestPriorGross, Currency::PLN)),
        ];

        return $this;
    }

    /**
     * The axes of a product (the variants are added with variant()).
     *
     * @param list<string> $axes
     */
    public function axes(string $productId, array $axes): self
    {
        $this->axes[$productId] = $axes;

        return $this;
    }

    /**
     * The id of the product of a variant added without one: the variant id with another first group.
     */
    public static function productOf(string $variantId): string
    {
        return 'aaaaaaaa'.substr($variantId, 8);
    }

    /**
     * @return array{summaries: list<list<string>>, current: list<list<string>>, of: list<list<string>>}
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<list<string>> the product ids of each call of variantsOf()
     */
    public function variantsOfCalls(): array
    {
        return $this->variantsOfCalls;
    }

    public function resetCalls(): void
    {
        $this->calls = ['summaries' => [], 'current' => [], 'of' => []];
        $this->variantsOfCalls = [];
    }

    public function forget(string $id): void
    {
        unset($this->variants[$id]);
    }

    public function summaries(array $ids): array
    {
        $this->calls['summaries'][] = self::keys($ids);
        $summaries = [];
        foreach ($ids as $id) {
            $variant = $this->variants[$id->toString()] ?? null;
            if (null !== $variant) {
                $summaries[] = new VariantSummary($id, ProductId::fromString($variant['productId']), $variant['name'], $variant['label'], null);
            }
        }

        return $summaries;
    }

    public function variantsOf(array $ids): array
    {
        $this->variantsOfCalls[] = array_map(static fn (ProductId $id): string => $id->toString(), $ids);
        $lists = [];
        foreach ($ids as $id) {
            $variants = [];
            foreach ($this->variants as $variantId => $variant) {
                if ($variant['productId'] === $id->toString()) {
                    $variants[] = new VariantLabel(VariantId::fromString($variantId), $variant['label']);
                }
            }
            if ([] !== $variants) {
                $lists[] = new ProductVariants($id, $this->axes[$id->toString()] ?? [], $variants);
            }
        }

        return $lists;
    }

    public function current(array $ids, bool $withLowestPriorPrice = false): array
    {
        $this->calls['current'][] = self::keys($ids);
        $this->lowestPriorPriceFlags[] = $withLowestPriorPrice;
        $prices = [];
        foreach ($ids as $id) {
            $key = $id->toString();
            if (isset($this->prices[$key])) {
                $promotion = $this->promotions[$key] ?? null;
                $prices[] = new CartPrice(
                    $id,
                    Money::of($this->prices[$key], Currency::PLN),
                    Money::of($promotion['regularGross'] ?? $this->prices[$key], Currency::PLN),
                    $promotion['promotion'] ?? null,
                );
            }
        }

        return $prices;
    }

    public function of(array $ids): array
    {
        $this->calls['of'][] = self::keys($ids);

        return array_map(function (VariantId $id): VariantAvailability {
            $available = $this->available[$id->toString()] ?? 0;

            return new VariantAvailability($id, $available, match (true) {
                0 === $available => StockLevel::OutOfStock,
                $available <= 5 => StockLevel::LowStock,
                default => StockLevel::InStock,
            });
        }, $ids);
    }

    /**
     * @param list<VariantId> $ids
     *
     * @return list<string>
     */
    private static function keys(array $ids): array
    {
        return array_map(static fn (VariantId $id): string => $id->toString(), $ids);
    }
}

<?php

declare(strict_types=1);

namespace App\Cart\Application;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\ProductAvailability;
use App\Cart\Application\Port\ProductSummary;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\ProductOffer;

/**
 * What Catalog, Pricing and Inventory said about a set of products, read once for a change or a view.
 */
final readonly class ProductData
{
    /** @var array<string, ProductSummary> */
    private array $summaries;
    /** @var array<string, Money> */
    private array $prices;
    /** @var array<string, int> */
    private array $available;

    /**
     * @param list<ProductSummary>      $summaries
     * @param list<CartPrice>           $prices
     * @param list<ProductAvailability> $availability
     */
    public function __construct(array $summaries, array $prices, array $availability)
    {
        $byId = [];
        foreach ($summaries as $summary) {
            $byId[$summary->id->toString()] = $summary;
        }
        $this->summaries = $byId;
        $byId = [];
        foreach ($prices as $price) {
            $byId[$price->productId->toString()] = $price->gross;
        }
        $this->prices = $byId;
        $byId = [];
        foreach ($availability as $one) {
            $byId[$one->productId->toString()] = $one->available;
        }
        $this->available = $byId;
    }

    /**
     * Null when Catalog does not know the product.
     */
    public function summary(ProductId $id): ?ProductSummary
    {
        return $this->summaries[$id->toString()] ?? null;
    }

    /**
     * Null when the product has no current price.
     */
    public function price(ProductId $id): ?Money
    {
        return $this->prices[$id->toString()] ?? null;
    }

    /**
     * 0 when Inventory gave nothing for the product.
     */
    public function available(ProductId $id): int
    {
        return $this->available[$id->toString()] ?? 0;
    }

    public function offerFor(ProductId $id): ProductOffer
    {
        return new ProductOffer(null !== $this->price($id), $this->available($id));
    }
}

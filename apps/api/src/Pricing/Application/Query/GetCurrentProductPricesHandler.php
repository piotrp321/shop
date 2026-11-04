<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\ProductDirectory;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\VatRates;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Transitional (removed with GET /api/v1/prices in task VARIANTS-4): keeps the product contract of the store list
 * and of the cart while prices are kept per variant.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCurrentProductPricesHandler
{
    public function __construct(
        private ProductDirectory $products,
        private VariantPriceRepository $prices,
        private VatRates $rates,
    ) {
    }

    /**
     * The variants of all the products from Catalog with one call, the prices of the single variants with one read.
     * A product with exactly one variant has the price of that variant when it is current (see GetCurrentPrices);
     * a product with more variants, without variants (unknown to Catalog) or without a current price is left out.
     * The prices follow the order of the request, a repeated id answers once.
     *
     * @return list<CurrentProductPrice>
     *
     * @throws InvalidCurrentPricesQuery
     */
    public function __invoke(GetCurrentProductPrices $query): array
    {
        $count = \count($query->productIds);
        if ($count < 1 || $count > GetCurrentProductPrices::MAX_PRODUCTS) {
            throw InvalidCurrentPricesQuery::productCountOutOfRange();
        }

        $ids = [];
        foreach ($query->productIds as $raw) {
            try {
                $id = ProductId::fromString($raw);
            } catch (\InvalidArgumentException) {
                throw InvalidCurrentPricesQuery::notAProductId();
            }
            $ids[$id->toString()] = $id;
        }

        $single = [];
        foreach ($this->products->variantsOf(array_values($ids)) as $productId => $variants) {
            if (1 === \count($variants)) {
                $single[$productId] = $variants[0]->id;
            }
        }
        if ([] === $single) {
            return [];
        }

        $stored = [];
        foreach ($this->prices->findMany(array_values($single)) as $price) {
            if ($price->isCurrent($this->rates)) {
                $stored[$price->id()->toString()] = $price;
            }
        }

        $prices = [];
        foreach (array_keys($ids) as $productId) {
            $variantId = $single[$productId] ?? null;
            $price = null === $variantId ? null : $stored[$variantId->toString()] ?? null;
            if (null !== $price) {
                $breakdown = $price->breakdown();
                $prices[] = new CurrentProductPrice($productId, $breakdown->gross()->amount(), $breakdown->currency()->value);
            }
        }

        return $prices;
    }
}

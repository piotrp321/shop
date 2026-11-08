<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\CartPrices;
use App\Cart\Application\Port\ProductCatalog;
use App\Cart\Application\Port\ProductVariants;
use App\Cart\Application\Port\StockAvailability;
use App\Cart\Application\Port\VariantAvailability;
use App\Cart\Application\View\FromPromotionView;
use App\Cart\Application\View\ProductOfferView;
use App\Cart\Application\View\VariantOfferView;
use App\Cart\Application\View\VariantPromotionView;
use App\Cart\Domain\Currency;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\ProductOfferSummary;
use App\Cart\Domain\StockLevel;
use App\Cart\Domain\VariantOffer;
use App\Cart\Domain\VariantPromotion;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductOffersHandler
{
    public function __construct(
        private ProductCatalog $catalog,
        private CartPrices $prices,
        private StockAvailability $stock,
    ) {
    }

    /**
     * The variants of all the products with one call to Catalog, then their prices and their stock with one call to
     * each of Pricing and Inventory (none without variants). No lock: the offers are a read for the list, an addition
     * to the cart checks again. One offer per requested product, in the order of the request, a repeated id once.
     *
     * @return list<ProductOfferView>
     *
     * @throws InvalidProductOffersQuery
     */
    public function __invoke(GetProductOffers $query): array
    {
        $count = \count($query->productIds);
        if ($count < 1 || $count > GetProductOffers::MAX_PRODUCTS) {
            throw InvalidProductOffersQuery::productCountOutOfRange();
        }

        $ids = [];
        foreach ($query->productIds as $raw) {
            try {
                $id = ProductId::fromString($raw);
            } catch (\InvalidArgumentException) {
                throw InvalidProductOffersQuery::notAProductId();
            }
            $ids[$id->toString()] = $id;
        }

        $byProduct = [];
        $variantIds = [];
        foreach ($this->catalog->variantsOf(array_values($ids)) as $list) {
            $byProduct[$list->productId->toString()] = $list;
            foreach ($list->variants as $variant) {
                $variantIds[] = $variant->variantId;
            }
        }

        $prices = [];
        $stock = [];
        if ([] !== $variantIds) {
            foreach ($this->prices->current($variantIds, $query->withLowestPriorPrice) as $price) {
                $prices[$price->variantId->toString()] = $price;
            }
            foreach ($this->stock->of($variantIds) as $one) {
                $stock[$one->variantId->toString()] = $one;
            }
        }

        $offers = [];
        foreach (array_keys($ids) as $productId) {
            $offers[] = self::offer($productId, $byProduct[$productId] ?? null, $prices, $stock);
        }

        return $offers;
    }

    /**
     * @param array<string, CartPrice>           $prices by variant id
     * @param array<string, VariantAvailability> $stock  by variant id
     */
    private static function offer(string $productId, ?ProductVariants $list, array $prices, array $stock): ProductOfferView
    {
        $offers = [];
        $views = [];
        foreach ($list->variants ?? [] as $variant) {
            $key = $variant->variantId->toString();
            $available = $stock[$key] ?? null;
            // A variant Inventory gave nothing for has no unit.
            $price = $prices[$key] ?? null;
            $offer = new VariantOffer($price?->gross, $available->available ?? 0, $available->level ?? StockLevel::OutOfStock, $price?->promotion);
            $offers[] = $offer;
            $views[] = new VariantOfferView(
                $key,
                $variant->label,
                $offer->gross?->amount(),
                $offer->level->value,
                $offer->isPurchasable(),
                $price?->regularGross->amount(),
                null === $offer->promotion ? null : new VariantPromotionView($offer->promotion->endsAt, self::lowestPrior($offer->promotion)),
            );
        }
        $summary = ProductOfferSummary::of($offers);

        return new ProductOfferView(
            $productId,
            $list->variantAxes ?? [],
            $summary->fromGross?->amount(),
            $summary->hasPriceRange,
            Currency::PLN->value,
            $summary->level->value,
            $views,
            null === $summary->fromPromotion ? null : new FromPromotionView(self::lowestPrior($summary->fromPromotion)),
        );
    }

    private static function lowestPrior(VariantPromotion $promotion): ?int
    {
        return $promotion->lowestPriorGross?->amount();
    }
}

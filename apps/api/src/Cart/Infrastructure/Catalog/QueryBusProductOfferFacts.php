<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Catalog;

use App\Cart\Application\Query\GetProductOffers;
use App\Cart\Application\View\ProductOfferView;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Catalog\Application\Port\ProductOfferFacts;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\VariantFacts;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Catalog's search index reads the offers of the product list (Cart's GetProductOffers on query.bus), so the index
 * knows what can be bought and the "from" price by the same rule as the tile. One query per batch of
 * {@see GetProductOffers::MAX_PRODUCTS} products.
 */
final class QueryBusProductOfferFacts implements ProductOfferFacts
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function of(array $productIds): array
    {
        $facts = [];
        foreach (array_chunk(array_values(array_unique($productIds)), GetProductOffers::MAX_PRODUCTS) as $batch) {
            try {
                $offers = $this->handle(new GetProductOffers($batch));
            } catch (HandlerFailedException $failure) {
                $cause = QueryBusFailure::cause($failure);

                throw new OfferFactsUnavailable('Reading the product offers failed: '.$cause->getMessage(), previous: $cause);
            }
            \assert(\is_array($offers));
            foreach ($offers as $offer) {
                \assert($offer instanceof ProductOfferView);
                $facts[$offer->productId] = self::facts($offer);
            }
        }

        return $facts;
    }

    private static function facts(ProductOfferView $offer): ProductFacts
    {
        $variants = [];
        foreach ($offer->variants as $variant) {
            $variants[$variant->variantId] = new VariantFacts($variant->gross, $variant->purchasable);
        }

        return new ProductFacts($offer->fromGross, $variants);
    }
}

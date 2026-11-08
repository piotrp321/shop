<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Pricing;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\CartPrices;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\VariantId;
use App\Cart\Domain\VariantPromotion;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\PriceSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The current (effective) gross prices of variants through Pricing's GetCurrentPrices on the query bus, with the
 * catalog gross and the promotion that gives the price. A price in a currency the cart does not sell in counts as no
 * current price.
 */
final class QueryBusCartPrices implements CartPrices
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function current(array $ids, bool $withLowestPriorPrice = false): array
    {
        if ([] === $ids) {
            return [];
        }

        try {
            $current = $this->handle(new GetCurrentPrices(array_map(static fn (VariantId $id): string => $id->toString(), $ids), $withLowestPriorPrice));
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert(\is_array($current));

        $prices = [];
        foreach ($current as $price) {
            \assert($price instanceof PriceSnapshot);
            $currency = Currency::tryFrom($price->currency);
            if (null !== $currency) {
                $promotion = $price->promotion;
                $prices[] = new CartPrice(
                    VariantId::fromString($price->variantId),
                    Money::of($price->gross, $currency),
                    Money::of($price->regularGross, $currency),
                    null === $promotion ? null : new VariantPromotion(
                        $promotion->endsAt,
                        null === $promotion->lowestPriorGross ? null : Money::of($promotion->lowestPriorGross, $currency),
                    ),
                );
            }
        }

        return $prices;
    }
}

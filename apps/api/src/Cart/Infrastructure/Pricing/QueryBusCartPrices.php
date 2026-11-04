<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Pricing;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\CartPrices;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Pricing\Application\Query\CurrentProductPrice;
use App\Pricing\Application\Query\GetCurrentProductPrices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The current gross prices through Pricing on the query bus. A price in a currency the cart does not sell in counts as
 * no current price.
 *
 * Transitional until the cart lines are kept per variant (VARIANTS-4): Pricing keeps prices per variant, and
 * GetCurrentProductPrices gives a product with exactly one variant (in Catalog's ListProductVariants) the current price
 * of that variant and a product with more variants none, the rule of GET /api/v1/prices.
 */
final class QueryBusCartPrices implements CartPrices
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function current(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        try {
            $current = $this->handle(new GetCurrentProductPrices(array_map(static fn (ProductId $id): string => $id->toString(), $ids)));
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert(\is_array($current));

        $prices = [];
        foreach ($current as $price) {
            \assert($price instanceof CurrentProductPrice);
            $currency = Currency::tryFrom($price->currency);
            if (null !== $currency) {
                $prices[] = new CartPrice(ProductId::fromString($price->productId), Money::of($price->gross, $currency));
            }
        }

        return $prices;
    }
}

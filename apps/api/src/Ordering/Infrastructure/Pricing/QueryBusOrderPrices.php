<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Pricing;

use App\Ordering\Application\Port\OrderPrices;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\BusFailure;
use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\PriceSnapshot;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The current prices through Pricing's GetCurrentPrices on the query bus (read from the Pricing tables, in the
 * transaction of the placement). A price in a currency an order is not placed in counts as no current price.
 */
final class QueryBusOrderPrices implements OrderPrices
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function current(array $variantIds): array
    {
        try {
            $current = $this->handle(new GetCurrentPrices(array_map(static fn (VariantId $id): string => $id->toString(), $variantIds)));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
        \assert(\is_array($current));

        $prices = [];
        foreach ($current as $price) {
            \assert($price instanceof PriceSnapshot);
            $currency = Currency::tryFrom($price->currency);
            if (null !== $currency) {
                $prices[VariantId::fromString($price->variantId)->toString()] = PriceCopy::of($price->net, $price->vat, $price->gross, $price->vatRateBp, $price->taxClass, $currency);
            }
        }

        return $prices;
    }
}

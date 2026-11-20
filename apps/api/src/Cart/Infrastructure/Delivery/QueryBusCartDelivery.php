<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Delivery;

use App\Cart\Application\Port\CartDelivery;
use App\Cart\Application\Port\CartDeliveryOption;
use App\Cart\Application\Port\CartDeliveryQuote;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Delivery\Application\Query\DeliveryOptionView;
use App\Delivery\Application\Query\DeliveryQuoteView;
use App\Delivery\Application\Query\QuoteDelivery;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The delivery of the cart through Delivery's QuoteDelivery on the query bus. The tracking template of a method is
 * left out: the cart never shows it.
 */
final class QueryBusCartDelivery implements CartDelivery
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function quote(int $goodsGross): CartDeliveryQuote
    {
        try {
            $quote = $this->handle(new QuoteDelivery($goodsGross));
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert($quote instanceof DeliveryQuoteView);

        return new CartDeliveryQuote(
            $quote->fromGross,
            $quote->missingToFreeGross,
            array_map(
                static fn (DeliveryOptionView $option): CartDeliveryOption => new CartDeliveryOption($option->methodId, $option->name, $option->description, $option->costGross, $option->freeFromGross, $option->missingToFreeGross),
                $quote->options,
            ),
        );
    }
}

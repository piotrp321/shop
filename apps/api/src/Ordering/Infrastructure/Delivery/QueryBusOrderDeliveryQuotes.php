<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Delivery;

use App\Delivery\Application\Query\DeliveryQuoteView;
use App\Delivery\Application\Query\QuoteDelivery;
use App\Ordering\Application\Port\OrderDeliveryQuotes;
use App\Ordering\Application\Port\QuotedDeliveryMethod;
use App\Ordering\Infrastructure\BusFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The delivery of an order through Delivery's QuoteDelivery on the query bus (a read of the active methods without a
 * lock, in the transaction of the placement, decision A7): the option of the chosen method, or none when it is not
 * among the active ones.
 */
final class QueryBusOrderDeliveryQuotes implements OrderDeliveryQuotes
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function quote(string $methodId, int $goodsGross): ?QuotedDeliveryMethod
    {
        try {
            $quote = $this->handle(new QuoteDelivery($goodsGross));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
        \assert($quote instanceof DeliveryQuoteView);

        foreach ($quote->options as $option) {
            if (strtolower($option->methodId) === $methodId) {
                return new QuotedDeliveryMethod($methodId, $option->name, $option->trackingUrlTemplate, $option->costGross);
            }
        }

        return null;
    }
}

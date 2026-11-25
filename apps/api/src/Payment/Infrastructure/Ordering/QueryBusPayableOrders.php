<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Ordering;

use App\Ordering\Application\OrderAccess;
use App\Ordering\Application\Query\GetOrderForPayment;
use App\Ordering\Application\Query\GetOrderPayability;
use App\Ordering\Application\Query\OrderForPayment;
use App\Ordering\Application\Query\OrderPayabilities as OrderingPayabilities;
use App\Payment\Application\OrderPayabilities;
use App\Payment\Application\OrderPayability;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\Port\PayableOrders;
use App\Payment\Domain\Currency;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderState;
use App\Payment\Domain\OrderToPay;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The orders through Ordering's queries on query.bus: GetOrderForPayment (inside the transaction of StartPayment it
 * reads on the same connection; Ordering decides the access) and GetOrderPayability. The answers are translated into
 * Payment's own types. A failure of a query is passed on as its cause.
 */
final class QueryBusPayableOrders implements PayableOrders
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function find(OrderId $orderId, PaymentAccess $access): ?OrderToPay
    {
        $orderAccess = null !== $access->token ? OrderAccess::guest($access->token) : OrderAccess::customer($access->customerId ?? '');
        $order = $this->ask(new GetOrderForPayment($orderId->toString(), $orderAccess));
        if (null === $order) {
            return null;
        }
        \assert($order instanceof OrderForPayment);

        return new OrderToPay(
            OrderId::fromString($order->orderId),
            $order->number,
            OrderState::from($order->statusForPayment),
            $order->totalGross,
            Currency::from($order->currency),
            $order->paymentDeadline,
            $order->payable,
        );
    }

    public function payabilityOf(array $orderIds): OrderPayabilities
    {
        $answer = $this->ask(new GetOrderPayability(array_map(static fn (OrderId $id): string => $id->toString(), $orderIds)));
        \assert($answer instanceof OrderingPayabilities);
        $payability = [];
        foreach ($answer->orders as $order) {
            $payability[$order->orderId] = new OrderPayability(OrderId::fromString($order->orderId), OrderState::from($order->statusForPayment), $order->payable);
        }

        return new OrderPayabilities($payability, $answer->unreadable);
    }

    private function ask(object $query): mixed
    {
        try {
            return $this->handle($query);
        } catch (HandlerFailedException $failure) {
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
    }
}

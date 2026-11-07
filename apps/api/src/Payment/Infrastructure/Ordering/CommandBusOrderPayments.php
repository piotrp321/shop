<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Ordering;

use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use App\Ordering\Application\Command\OrderChangedConcurrently;
use App\Payment\Application\OrderBusy;
use App\Payment\Application\OrderPaymentResult;
use App\Payment\Application\Port\OrderPayments;
use App\Payment\Domain\Amount;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Pays orders through Ordering's MarkOrderPaid on command.bus, dispatched inside the transaction of the webhook
 * (nested, a savepoint on the same connection). Its result becomes Payment's own; a version conflict of the order
 * becomes OrderBusy, any other failure passes on as its cause (e.g. OrderToPayNotFound, a lost connection), so the
 * whole webhook rolls back either way.
 */
final readonly class CommandBusOrderPayments implements OrderPayments
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function markPaid(OrderId $orderId, PaymentId $paymentId, Amount $amount, \DateTimeImmutable $executedAt): OrderPaymentResult
    {
        $command = new MarkOrderPaid($orderId->toString(), $paymentId->toString(), $amount->minorUnits(), $amount->currency()->value, $executedAt);
        try {
            $result = $this->commandBus->dispatch($command)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            $wrapped = $failure->getWrappedExceptions();
            $cause = 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;

            throw $cause instanceof OrderChangedConcurrently ? new OrderBusy($cause) : $cause;
        }
        \assert($result instanceof MarkOrderPaidResult);

        return match ($result) {
            MarkOrderPaidResult::Paid => OrderPaymentResult::Paid,
            MarkOrderPaidResult::AlreadyPaid => OrderPaymentResult::AlreadyPaid,
            MarkOrderPaidResult::PaidByAnotherPayment => OrderPaymentResult::PaidByAnotherPayment,
            MarkOrderPaidResult::PaidAfterDeadline => OrderPaymentResult::PaidAfterDeadline,
            MarkOrderPaidResult::OrderCancelled => OrderPaymentResult::OrderCancelled,
        };
    }
}

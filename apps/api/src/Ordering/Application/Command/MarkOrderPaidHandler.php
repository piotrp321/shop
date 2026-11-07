<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Domain\InvalidOrder;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\PaymentResult;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Pays the order if the domain lets the payment pay it, in the transaction of command.bus (nested in the webhook of
 * Payment): the order with its OrderPaid in the outbox. The result of the domain becomes a result of the application
 * and a version conflict OrderChangedConcurrently, so the caller needs nothing of the Ordering domain.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class MarkOrderPaidHandler
{
    public function __construct(
        private OrderRepository $orders,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @throws OrderToPayNotFound
     * @throws OrderChangedConcurrently
     * @throws InvalidOrder             the amount is not the gross total of the order (a logic error)
     */
    public function __invoke(MarkOrderPaid $command): MarkOrderPaidResult
    {
        try {
            $order = $this->orders->get(OrderId::fromString($command->orderId));
        } catch (\InvalidArgumentException|OrderNotFound $notFound) {
            throw new OrderToPayNotFound($notFound);
        }

        $result = $order->markPaid(PaymentId::fromString($command->paymentId), $command->amount, $command->currency, $command->executedAt, $this->clock->now());
        if (PaymentResult::Paid === $result) {
            try {
                $this->orders->save($order);
            } catch (OrderModifiedConcurrently $conflict) {
                throw new OrderChangedConcurrently($conflict);
            }
            $this->events->publish(...$order->releaseEvents());
        }

        return match ($result) {
            PaymentResult::Paid => MarkOrderPaidResult::Paid,
            PaymentResult::AlreadyPaid => MarkOrderPaidResult::AlreadyPaid,
            PaymentResult::PaidByAnotherPayment => MarkOrderPaidResult::PaidByAnotherPayment,
            PaymentResult::PaidAfterDeadline => MarkOrderPaidResult::PaidAfterDeadline,
            PaymentResult::OrderCancelled => MarkOrderPaidResult::OrderCancelled,
        };
    }
}

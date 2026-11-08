<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\DiscountCodeRedemptions;
use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Application\Port\UnpaidOrderExpiry;
use App\Ordering\Domain\ExpiryResult;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Shared\Domain\Event\DomainEventPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cancels an unpaid order in the transaction of command.bus: the release of its reservation (Inventory, nested), the
 * release of the usage of its discount code (Pricing, nested; called for every order, without effect for one without
 * a code, decision D4), the cancelled order and OrderCancelled in the outbox together or not at all. It always decides
 * on the current state and deadline of the order, so a message delivered again changes nothing.
 *
 * The order is locked first, before the release reaches Inventory, like the changes of staff, so the expiry and a
 * change of staff run one after another and never deadlock. The usage is released after the reservation and Pricing
 * does not lock the code for it, so the locks (order, stock, usage) form no cycle with a placement (cart, code, stock).
 *
 * A message that came too early (the transport stores the moment to the second) is sent again for a second after
 * the expiry. A paid or cancelled order needs nothing. An order paid concurrently makes the save fail on its version;
 * the exception rolls everything back (also the release) and the retry of the message sees the paid order. Any
 * failure of the release (a reservation Inventory does not know or one already committed) is thrown as it is.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ExpireUnpaidOrderHandler
{
    private const string RETRY_DELAY = '+1 second';

    public function __construct(
        private OrderRepository $orders,
        private StockReservations $reservations,
        private DiscountCodeRedemptions $redemptions,
        private UnpaidOrderExpiry $expiry,
        private DomainEventPublisher $events,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OrderModifiedConcurrently the order changed meanwhile; Messenger retries the message
     */
    public function __invoke(ExpireUnpaidOrder $command): void
    {
        try {
            $order = $this->orders->lock(OrderId::fromString($command->orderId));
        } catch (OrderNotFound) {
            // Orders are never deleted, only the demo seed removes them (with the queue of these messages).
            $this->logger->warning('The order of ExpireUnpaidOrder does not exist; nothing to cancel.', ['orderId' => $command->orderId]);

            return;
        }

        switch ($order->expireUnpaid($this->clock->now())) {
            case ExpiryResult::Cancelled:
                // The reservation and the usage first: the flushes of the nested commands of Inventory and Pricing then
                // have nothing of Ordering.
                $this->reservations->release($order->id());
                $this->redemptions->release($order->id());
                $this->orders->save($order);
                $this->events->publish(...$order->releaseEvents());
                break;
            case ExpiryResult::TooEarly:
                $this->expiry->schedule($order->id(), $order->expiryDueAt()->modify(self::RETRY_DELAY));
                break;
            case ExpiryResult::NotApplicable:
                break;
        }
    }
}

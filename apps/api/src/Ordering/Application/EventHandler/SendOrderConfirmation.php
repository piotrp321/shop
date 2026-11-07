<?php

declare(strict_types=1);

namespace App\Ordering\Application\EventHandler;

use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Application\Port\OrderConfirmationMailer;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Domain\OrderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends the confirmation email of a placed order. The event carries only the id; the handler reads the order and
 * sends the email once through NotificationSender, with no command and no transaction around the mail transport,
 * and without changing the order.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class SendOrderConfirmation
{
    public function __construct(
        private OrderRepository $orders,
        private NotificationSender $notifications,
        private OrderConfirmationMailer $mailer,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException the order does not exist; the message goes to `failed`
     * @throws RecoverableMessageHandlingException   another handler is sending the email; retried after its lease
     * @throws \Throwable                            the mail transport failed; Messenger retries the message
     */
    public function __invoke(OrderPlaced $event): void
    {
        try {
            $order = $this->orders->get(OrderId::fromString($event->orderId));
        } catch (OrderNotFound $e) {
            // An order is never deleted, only the demo seed removes them: an event whose order is gone is not
            // something a retry fixes. Nothing is claimed for it.
            throw new UnrecoverableMessageHandlingException(\sprintf('Order "%s" from OrderPlaced does not exist.', $event->orderId), previous: $e);
        }
        $this->notifications->sendOnce($order->id(), NotificationKind::Confirmation, fn () => $this->mailer->send(OrderView::of($order)));
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Application\EventHandler;

use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\OrderShipped;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends the email "Zamówienie … wysłane" of a shipped order once, like SendOrderConfirmation.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class SendOrderShipped
{
    public function __construct(
        private OrderRepository $orders,
        private NotificationSender $notifications,
        private OrderStatusMailer $mailer,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException the order does not exist; the message goes to `failed`
     * @throws RecoverableMessageHandlingException   another handler is sending the email; retried after its lease
     * @throws \Throwable                            the mail transport failed; Messenger retries the message
     */
    public function __invoke(OrderShipped $event): void
    {
        try {
            $order = $this->orders->get(OrderId::fromString($event->orderId));
        } catch (OrderNotFound $e) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Order "%s" from OrderShipped does not exist.', $event->orderId), previous: $e);
        }
        $this->notifications->sendOnce($order->id(), NotificationKind::Shipped, fn () => $this->mailer->sendOrderShipped(OrderView::of($order)));
    }
}

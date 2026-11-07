<?php

declare(strict_types=1);

namespace App\Ordering\Application\EventHandler;

use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends the email "Zamówienie … anulowane" of an order cancelled for want of a payment once, like
 * SendOrderConfirmation.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class SendOrderCancelled
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
    public function __invoke(OrderCancelled $event): void
    {
        try {
            $order = $this->orders->get(OrderId::fromString($event->orderId));
        } catch (OrderNotFound $e) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Order "%s" from OrderCancelled does not exist.', $event->orderId), previous: $e);
        }
        $this->notifications->sendOnce($order->id(), NotificationKind::Cancelled, fn () => $this->mailer->sendOrderCancelled(OrderView::of($order)));
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Application\EventHandler;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\OrderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends the email "Płatność przyjęta" of a paid order, like SendOrderConfirmation: an order with the payment
 * notification marker gets nothing, the mail goes out before the marker is stored (a failure between the two sends
 * a second email on the retry), and of two handlers of one event at once only one stores the marker.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class SendPaymentReceived
{
    public function __construct(
        private OrderRepository $orders,
        private OrderStatusMailer $mailer,
        private Clock $clock,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException the order does not exist; the message goes to `failed`
     * @throws OrderModifiedConcurrently             another handler stored a change first; Messenger retries the message
     * @throws \Throwable                            the mail transport failed; Messenger retries the message
     */
    public function __invoke(OrderPaid $event): void
    {
        try {
            $order = $this->orders->get(OrderId::fromString($event->orderId));
        } catch (OrderNotFound $e) {
            throw new UnrecoverableMessageHandlingException(\sprintf('Order "%s" from OrderPaid does not exist.', $event->orderId), previous: $e);
        }
        if (null !== $order->paymentNotificationSentAt()) {
            return;
        }

        $this->mailer->sendPaymentReceived(OrderView::of($order));
        $order->markPaymentNotificationSent($this->clock->now());
        $this->orders->save($order);
    }
}

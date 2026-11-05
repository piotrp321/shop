<?php

declare(strict_types=1);

namespace App\Ordering\Application\EventHandler;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\OrderConfirmationMailer;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Domain\OrderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Sends the confirmation email of a placed order. The event carries only the id; the handler reads the order and
 * does its work itself, with no command and no transaction around the mail transport.
 *
 * The same event may arrive more than once, so an order with the confirmation marker gets nothing. The mail goes
 * out before the marker is stored: a failure between the two sends a second email on the retry, the reverse order
 * could lose the email. Two handlers of one event running at once may both send, but only one stores the marker;
 * the other gets OrderModifiedConcurrently and its retry finds the marker.
 */
#[AsMessageHandler(bus: 'event.bus', fromTransport: 'events')]
final readonly class SendOrderConfirmation
{
    public function __construct(
        private OrderRepository $orders,
        private OrderConfirmationMailer $mailer,
        private Clock $clock,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException the order does not exist; the message goes to `failed`
     * @throws OrderModifiedConcurrently             another handler stored the marker first; Messenger retries the message
     * @throws \Throwable                            the mail transport failed; Messenger retries the message
     */
    public function __invoke(OrderPlaced $event): void
    {
        try {
            $order = $this->orders->get(OrderId::fromString($event->orderId));
        } catch (OrderNotFound $e) {
            // An order is never deleted, only the demo seed removes them: an event whose order is gone is not
            // something a retry fixes.
            throw new UnrecoverableMessageHandlingException(\sprintf('Order "%s" from OrderPlaced does not exist.', $event->orderId), previous: $e);
        }
        if (null !== $order->confirmationSentAt()) {
            return;
        }

        $this->mailer->send(OrderView::of($order));
        $order->markConfirmationSent($this->clock->now());
        $this->orders->save($order);
    }
}

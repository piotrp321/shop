<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Mail;

use App\Ordering\Application\OrderCancelledByStaffText;
use App\Ordering\Application\OrderCancelledText;
use App\Ordering\Application\OrderShippedText;
use App\Ordering\Application\PaymentReceivedText;
use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\ReturnReceivedText;
use App\Ordering\Application\View\OrderView;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the emails about the payment, the shipment, the cancellation and the return as plain text through the mail transport, synchronously,
 * like SymfonyOrderConfirmationMailer (no message bus, so a transport failure reaches the caller).
 */
final readonly class SymfonyOrderStatusMailer implements OrderStatusMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private PaymentReceivedText $paymentReceived,
        private OrderCancelledText $orderCancelled,
        private OrderCancelledByStaffText $orderCancelledByStaff,
        private OrderShippedText $orderShipped,
        private ReturnReceivedText $returnReceived,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
    ) {
    }

    public function sendPaymentReceived(OrderView $order): void
    {
        $this->send($order, $this->paymentReceived->subject($order), $this->paymentReceived->body($order));
    }

    public function sendOrderCancelled(OrderView $order): void
    {
        $this->send($order, $this->orderCancelled->subject($order), $this->orderCancelled->body($order));
    }

    public function sendOrderCancelledByStaff(OrderView $order): void
    {
        $this->send($order, $this->orderCancelledByStaff->subject($order), $this->orderCancelledByStaff->body($order));
    }

    public function sendOrderShipped(OrderView $order): void
    {
        $this->send($order, $this->orderShipped->subject($order), $this->orderShipped->body($order));
    }

    public function sendReturnReceived(OrderView $order): void
    {
        $this->send($order, $this->returnReceived->subject($order), $this->returnReceived->body($order));
    }

    private function send(OrderView $order, string $subject, string $body): void
    {
        $this->mailer->send(
            (new Email())
                ->from(Address::create($this->from))
                ->to($order->email)
                ->subject($subject)
                ->text($body),
        );
    }
}

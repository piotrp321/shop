<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Mail;

use App\Ordering\Application\OrderCancelledText;
use App\Ordering\Application\PaymentReceivedText;
use App\Ordering\Application\Port\OrderStatusMailer;
use App\Ordering\Application\View\OrderView;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the emails about the payment and the cancellation as plain text through the mail transport, synchronously,
 * like SymfonyOrderConfirmationMailer (no message bus, so a transport failure reaches the caller).
 */
final readonly class SymfonyOrderStatusMailer implements OrderStatusMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private PaymentReceivedText $paymentReceived,
        private OrderCancelledText $orderCancelled,
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

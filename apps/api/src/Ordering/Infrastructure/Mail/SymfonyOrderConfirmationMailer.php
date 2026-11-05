<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Mail;

use App\Ordering\Application\OrderConfirmationText;
use App\Ordering\Application\Port\OrderConfirmationMailer;
use App\Ordering\Application\View\OrderView;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the confirmation as a plain-text email through the mail transport (SMTP, `MAILER_DSN`). The mailer has no
 * message bus (`framework.mailer.message_bus: false`), so send() returns only after the transport accepted the mail,
 * and a transport failure is thrown to the caller (TransportExceptionInterface).
 */
final readonly class SymfonyOrderConfirmationMailer implements OrderConfirmationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private OrderConfirmationText $text,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
    ) {
    }

    public function send(OrderView $order): void
    {
        $this->mailer->send(
            (new Email())
                ->from(Address::create($this->from))
                ->to($order->email)
                ->subject($this->text->subject($order))
                ->text($this->text->body($order)),
        );
    }
}

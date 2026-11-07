<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Mail;

use App\Ordering\Application\OrderConfirmationText;
use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\OrderTotalsView;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Application\View\ShippingAddressView;
use App\Ordering\Infrastructure\Mail\SymfonyOrderConfirmationMailer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

/**
 * The adapter against the real SMTP of Mailpit (MAILER_DSN of the test environment): the mail is there when send()
 * returns, and an SMTP server that cannot be reached is an exception, not a message handed to a bus.
 */
final class SymfonyOrderConfirmationMailerTest extends KernelTestCase
{
    private const string FROM = 'Rytm <zamowienia@rytm.localhost>';

    private MailpitInbox $inbox;

    protected function setUp(): void
    {
        $this->inbox = new MailpitInbox(\sprintf('mailer-%s@example.test', bin2hex(random_bytes(6))));
    }

    protected function tearDown(): void
    {
        $this->inbox->clear();
        parent::tearDown();
    }

    public function testTheMailIsInMailpitWhenSendReturns(): void
    {
        $view = $this->view();
        $mailer = $this->adapter(self::getContainer()->get('mailer.mailer'));

        $mailer->send($view);

        $messages = $this->inbox->awaitMessages(1);
        self::assertCount(1, $messages, 'delivered synchronously, no worker involved');
        self::assertSame('Potwierdzenie zamówienia R-100001', $messages[0]['subject']);
        self::assertSame(self::FROM, $messages[0]['from']);
        self::assertSame([$this->inbox->recipient], $messages[0]['to']);
        // Mailpit adds a trailing newline to the text it returns.
        self::assertSame(trim((new OrderConfirmationText())->body($view)), trim(str_replace("\r\n", "\n", $messages[0]['text'])));
    }

    public function testAnUnreachableSmtpServerIsAnExceptionOfTheTransport(): void
    {
        // Nothing listens on port 1. A mailer with a message bus would hand the mail over and return.
        $mailer = $this->adapter(new Mailer(Transport::fromDsn('smtp://127.0.0.1:1')));

        try {
            $mailer->send($this->view());
            self::fail('Expected the transport to fail.');
        } catch (TransportExceptionInterface) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $this->inbox->count());
    }

    public function testTheMailerOfTheContainerHasNoMessageBus(): void
    {
        // With a bus, send() would put a SendEmailMessage on command.bus and return before SMTP was tried.
        $mailer = self::getContainer()->get('mailer.mailer');
        self::assertInstanceOf(Mailer::class, $mailer);

        self::assertNull((new \ReflectionProperty(Mailer::class, 'bus'))->getValue($mailer));
    }

    private function adapter(mixed $mailer): SymfonyOrderConfirmationMailer
    {
        self::assertInstanceOf(MailerInterface::class, $mailer);

        return new SymfonyOrderConfirmationMailer($mailer, new OrderConfirmationText(), self::FROM);
    }

    private function view(): OrderView
    {
        return new OrderView(
            '0192f0c4-7c1a-7c4e-9a6e-0000000000aa',
            'R-100001',
            'new',
            $this->inbox->recipient,
            'Jan Kowalski',
            '+48600100200',
            new ShippingAddressView('Długa 1', '00-950', 'Warszawa', 'PL'),
            [new OrderLineView('0192f0c4-7c1a-7c4e-9a6e-000000000001', 'Buty Asfalt', '42', 2, 61_728, 123_456)],
            new OrderTotalsView(100_000, 23_456, 123_456, 'PLN'),
            new \DateTimeImmutable('2026-11-05 12:00:00+00:00'),
            new \DateTimeImmutable('2026-11-05 12:30:00+00:00'),
            'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM',
        );
    }
}

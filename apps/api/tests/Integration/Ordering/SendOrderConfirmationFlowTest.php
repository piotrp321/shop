<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\OrderConfirmationText;
use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Infrastructure\Mail\SymfonyOrderConfirmationMailer;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalOrderNotifications;
use App\Ordering\Infrastructure\SystemClock;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\Mail\MailpitInbox;
use App\Tests\Integration\Ordering\Mail\ToggleableOrderConfirmationMailer;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The confirmation email on real infrastructure (the `test_messaging` kernel): PlaceOrder → outbox (Postgres) →
 * relay → RabbitMQ (`events`, vhost `test`) → SendOrderConfirmation → SMTP of Mailpit. The test consumes the
 * transports in its own process, so it does not depend on the worker of the stack, and reads Mailpit by an address
 * of its own.
 */
final class SendOrderConfirmationFlowTest extends MessagingKernelTestCase
{
    private const string FROM = 'Worf <zamowienia@worf.localhost>';

    private MailpitInbox $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inbox = new MailpitInbox(\sprintf('flow-%s@example.test', bin2hex(random_bytes(6))));
    }

    protected function tearDown(): void
    {
        $this->inbox->clear();
        parent::tearDown();
    }

    public function testAPlacedOrderGetsOneEmailThroughTheOutboxRabbitMqAndTheWorker(): void
    {
        $order = $this->place();

        $this->consumeOutboxAndEvents();

        $messages = $this->inbox->awaitMessages(1);
        self::assertCount(1, $messages);
        self::assertSame('Potwierdzenie zamówienia '.$order->number, $messages[0]['subject']);
        self::assertStringContainsString($order->number, $messages[0]['text']);
        self::assertStringContainsString('Suma brutto: 123,00 zł', $messages[0]['text']);
        self::assertNotNull($this->confirmationSentAt($order->orderId));
        self::assertSame(0, $this->rows('outbox'));
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
    }

    public function testTheSameEventDeliveredTwiceInARowGivesOneEmail(): void
    {
        $order = $this->place();
        $this->consumeOutboxAndEvents();
        // At-least-once delivery: the broker hands the same event over again.
        $this->transport('events')->send(new Envelope(
            new OrderPlaced($order->orderId, new \DateTimeImmutable()),
            [new BusNameStamp('event.bus')],
        ));

        $this->consume('events');

        // A failed handler would put the message back through the retry delay (1 s ±10 %), so wait it out.
        usleep(1_500_000);
        self::assertCount(1, $this->inbox->awaitMessages(1));
        self::assertSame(0, $this->eventsInQueue());
        self::assertSame(0, $this->rows('failed'));
    }

    public function testASmtpFailureIsRetriedAndOneEmailArrivesOnceSmtpIsBack(): void
    {
        $mailer = $this->smtpSwitch();
        $order = $this->place();

        $this->consumeOutboxAndEvents();

        self::assertSame(1, $mailer->failedAttempts);
        self::assertSame(0, $this->inbox->count());
        self::assertNull($this->confirmationSentAt($order->orderId));
        self::assertSame(0, $this->rows('failed'), 'the first failure is a retry, not a failure of the message');

        $mailer->up = true;
        $this->consume('events', 10);

        self::assertSame(1, $mailer->failedAttempts);
        self::assertCount(1, $this->inbox->awaitMessages(1));
        self::assertNotNull($this->confirmationSentAt($order->orderId));
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
    }

    public function testAfterTheRetriesAreSpentTheMessageIsInFailedAndTheOrderIsUnmarked(): void
    {
        $mailer = $this->smtpSwitch();
        $order = $this->place();

        $this->consumeOutboxAndEvents();
        // `events` retries three times, 1 s, 3 s and 9 s apart.
        for ($retry = 1; $retry <= 3; ++$retry) {
            $this->consume('events', 20);
        }

        self::assertSame(4, $mailer->failedAttempts);
        self::assertSame(1, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
        self::assertSame(0, $this->inbox->count());
        self::assertNull($this->confirmationSentAt($order->orderId));
        self::assertSame(
            [null],
            $this->connection()->fetchFirstColumn("SELECT claimed_until FROM ordering_order_notification WHERE order_id = ? AND kind = 'confirmation'", [$order->orderId]),
            'every failed attempt released its claim',
        );
    }

    public function testAHeldClaimComesBackAfterTheLeaseAndEndsWithOneEmail(): void
    {
        $clock = new FixedOrderingClock(new \DateTimeImmutable('@'.time()));
        self::getContainer()->set(SystemClock::class, $clock);
        $order = $this->place();
        // Another handler claimed the sending of the confirmation and died before it marked the email sent; its lease
        // ends in 3 seconds.
        new DbalOrderNotifications($this->connection())->claim(OrderId::fromString($order->orderId), NotificationKind::Confirmation, $clock->now->modify('-5 minutes')->modify('+3 seconds'));

        $this->consumeOutboxAndEvents();

        self::assertSame(0, $this->inbox->count(), 'the claim of the other handler holds the sending');
        self::assertNull($this->confirmationSentAt($order->orderId));
        self::assertSame(0, $this->rows('failed'));

        // The message comes back one second after the end of the lease, 4 seconds after the first attempt.
        $clock->now = $clock->now->modify('+4 seconds');
        $start = microtime(true);
        $this->consume('events', 15);

        self::assertGreaterThanOrEqual(2.5, microtime(true) - $start, 'the retry waited for the end of the lease');
        self::assertCount(1, $this->inbox->awaitMessages(1));
        self::assertEquals($clock->now, $this->confirmationSentAt($order->orderId));
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
    }

    /**
     * The mailer of the handler: "down" is the adapter on an SMTP server nobody listens on, "up" the adapter of the
     * container (Mailpit). Starts down.
     */
    private function smtpSwitch(): ToggleableOrderConfirmationMailer
    {
        $down = new SymfonyOrderConfirmationMailer(new Mailer(Transport::fromDsn('smtp://127.0.0.1:1')), new OrderConfirmationText(), self::FROM);
        $mailer = new ToggleableOrderConfirmationMailer($down, $this->adapter());
        self::getContainer()->set(SymfonyOrderConfirmationMailer::class, $mailer);

        return $mailer;
    }

    private function adapter(): SymfonyOrderConfirmationMailer
    {
        $mailer = self::getContainer()->get('mailer.mailer');
        self::assertInstanceOf(MailerInterface::class, $mailer);

        return new SymfonyOrderConfirmationMailer($mailer, new OrderConfirmationText(), self::FROM);
    }

    private function place(): OrderView
    {
        $db = $this->connection();
        [$variant] = OrderingFixture::variant($db, 10_000, 5);
        $cart = CartFixture::cart($db, expiresAt: '+3 days', lines: [[$variant, 1]]);
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $result = $bus->dispatch(new PlaceOrder(CartReference::guest($cart), $this->inbox->recipient, 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', 12_300, 'key-'.$cart))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PlacedOrder::class, $result);
        self::assertSame(1, $this->rows('outbox'));

        return $result->order;
    }

    private function confirmationSentAt(string $orderId): ?\DateTimeImmutable
    {
        return OrderingFixture::notificationSentAt($this->connection(), $orderId, 'confirmation');
    }

    private function consumeOutboxAndEvents(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => 2, '--time-limit' => 10]);
        $tester->assertCommandIsSuccessful();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Infrastructure\SystemClock;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\Mail\MailpitInbox;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The emails about the payment and the cancellation on real infrastructure (the `test_messaging` kernel): the command
 * that changes the order → outbox (Postgres) → relay → RabbitMQ (`events`, vhost `test`) → SendPaymentReceived or
 * SendOrderCancelled → SMTP of Mailpit. Each test reads Mailpit by an address of its own.
 */
final class SendOrderStatusEmailsFlowTest extends MessagingKernelTestCase
{
    private MailpitInbox $inbox;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inbox = new MailpitInbox(\sprintf('status-%s@example.test', bin2hex(random_bytes(6))));
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('@'.time()));
        self::getContainer()->set(SystemClock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->inbox->clear();
        parent::tearDown();
    }

    public function testAPaidOrderGetsOnePaymentEmailAlsoWhenTheEventIsDeliveredTwice(): void
    {
        $order = $this->place();
        $paid = $this->bus()->dispatch(new MarkOrderPaid($order->orderId, Uuid::v7()->toRfc4122(), 12_300, 'PLN', $this->clock->now))->last(HandledStamp::class)?->getResult();
        self::assertSame(MarkOrderPaidResult::Paid, $paid);
        self::assertSame(2, $this->rows('outbox'), 'OrderPlaced and OrderPaid');

        $this->consumeOutboxAndEvents(4);
        $this->redeliver(new OrderPaid($order->orderId, $this->clock->now));

        $emails = $this->emails('Płatność przyjęta – zamówienie '.$order->number);
        self::assertCount(1, $emails);
        self::assertStringContainsString('Kwota: 123,00 zł', $emails[0]['text']);
        self::assertStringContainsString('Zamówienie przekazujemy do realizacji.', $emails[0]['text']);
        self::assertNotNull($this->connection()->fetchOne('SELECT payment_notification_sent_at FROM ordering_order WHERE id = ?', [$order->orderId]));
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
    }

    public function testACancelledOrderGetsOneCancellationEmailAlsoWhenTheEventIsDeliveredTwice(): void
    {
        $order = $this->place();
        $this->clock->now = $order->paymentDeadline->modify('+5 minutes');
        $this->bus()->dispatch(new Envelope(new ExpireUnpaidOrder($order->orderId), [new ReceivedStamp('scheduled')]));
        self::assertSame('cancelled', $this->connection()->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$order->orderId]));

        $this->consumeOutboxAndEvents(4);
        $this->redeliver(new OrderCancelled($order->orderId, 'payment_timeout', $this->clock->now));

        $emails = $this->emails('Zamówienie '.$order->number.' anulowane');
        self::assertCount(1, $emails);
        self::assertStringContainsString('Nie otrzymaliśmy płatności w terminie, więc anulowaliśmy zamówienie.', $emails[0]['text']);
        self::assertNotNull($this->connection()->fetchOne('SELECT cancellation_notification_sent_at FROM ordering_order WHERE id = ?', [$order->orderId]));
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
    }

    /**
     * At-least-once delivery: the broker hands the same event over again, after the first one was handled.
     */
    private function redeliver(object $event): void
    {
        $this->transport('events')->send(new Envelope($event, [new BusNameStamp('event.bus')]));
        $this->consume('events');
        // A failed handler would put the message back through the retry delay (1 s ±10 %), so wait it out.
        usleep(1_500_000);
    }

    /**
     * @return list<array{subject: string, from: string, to: list<string>, text: string}>
     */
    private function emails(string $subject): array
    {
        // The confirmation of the placement and the email under test.
        $messages = $this->inbox->awaitMessages(2);

        return array_values(array_filter($messages, static fn (array $message): bool => $message['subject'] === $subject));
    }

    private function place(): OrderView
    {
        $db = $this->connection();
        [$variant] = OrderingFixture::variant($db, 10_000, 5);
        $cart = CartFixture::cart($db, expiresAt: '+3 days', lines: [[$variant, 1]]);

        $result = $this->bus()->dispatch(new PlaceOrder(CartReference::guest($cart), $this->inbox->recipient, 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', 12_300, 'key-'.$cart))
            ->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PlacedOrder::class, $result);

        return $result->order;
    }

    private function consumeOutboxAndEvents(int $messages): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => $messages, '--time-limit' => 10]);
        $tester->assertCommandIsSuccessful();
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}

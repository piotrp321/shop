<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\OrderReturned;
use App\Ordering\Domain\OrderShipped;
use App\Ordering\Domain\StaffTransition;
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
 * The emails about the payment, the shipment, the cancellation and the return on real infrastructure (the
 * `test_messaging` kernel): the command that changes the order → outbox (Postgres) → relay → RabbitMQ (`events`, vhost
 * `test`) → SendPaymentReceived, SendOrderShipped, SendOrderCancelled or SendReturnReceived → SMTP of Mailpit. Each test
 * reads Mailpit by an address of its own.
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
        $version = $this->version($order->orderId);

        $this->consumeOutboxAndEvents(4);
        $this->redeliver(new OrderPaid($order->orderId, $this->clock->now));

        $emails = $this->emails('Płatność przyjęta – zamówienie '.$order->number);
        self::assertCount(1, $emails);
        self::assertStringContainsString('Kwota: 123,00 zł', $emails[0]['text']);
        self::assertStringContainsString('Zamówienie przekazujemy do realizacji.', $emails[0]['text']);
        self::assertNotNull(OrderingFixture::notificationSentAt($this->connection(), $order->orderId, 'payment_received'));
        self::assertSame($version, $this->version($order->orderId), 'sending the emails does not change the order');
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
    }

    public function testACancelledOrderGetsOneCancellationEmailAlsoWhenTheEventIsDeliveredTwice(): void
    {
        $order = $this->place();
        $this->clock->now = $order->paymentDeadline->modify('+5 minutes');
        $this->bus()->dispatch(new Envelope(new ExpireUnpaidOrder($order->orderId), [new ReceivedStamp('scheduled')]));
        self::assertSame('cancelled', $this->connection()->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$order->orderId]));
        $version = $this->version($order->orderId);

        $this->consumeOutboxAndEvents(4);
        $this->redeliver(new OrderCancelled($order->orderId, 'payment_timeout', $this->clock->now));

        $emails = $this->emails('Zamówienie '.$order->number.' anulowane');
        self::assertCount(1, $emails);
        self::assertStringContainsString('Nie otrzymaliśmy płatności w terminie, więc anulowaliśmy zamówienie.', $emails[0]['text']);
        self::assertNotNull(OrderingFixture::notificationSentAt($this->connection(), $order->orderId, 'cancelled'));
        self::assertSame($version, $this->version($order->orderId), 'sending the emails does not change the order');
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
    }

    public function testAShippedOrderGetsOneEmailWithTheAddress(): void
    {
        $order = $this->placeAndPay();
        $this->changeStatus($order->orderId, StaffTransition::Ship, 2, 'Kurier');

        $this->consumeOutboxAndEvents(6);
        $this->redeliver(new OrderShipped($order->orderId, $this->clock->now));

        $emails = $this->emails('Zamówienie '.$order->number.' wysłane', 3);
        self::assertCount(1, $emails);
        self::assertStringContainsString("Zamówienie zostało wysłane na adres:\nJan Kowalski\nDługa 1\n00-950 Warszawa", str_replace("\r\n", "\n", $emails[0]['text']), 'SMTP sends the lines with CRLF');
        self::assertStringNotContainsString('Kurier', $emails[0]['text'], 'the note of staff stays in the panel');
        self::assertNotNull(OrderingFixture::notificationSentAt($this->connection(), $order->orderId, 'shipped'));
        self::assertSame(0, $this->rows('failed'));
    }

    public function testAnOrderCancelledByStaffGetsOneEmailWithoutTheTextAboutThePayment(): void
    {
        $order = $this->placeAndPay();
        $this->changeStatus($order->orderId, StaffTransition::Cancel, 2, 'Brak towaru');

        $this->consumeOutboxAndEvents(6);
        $this->redeliver(new OrderCancelled($order->orderId, 'staff', $this->clock->now));

        $emails = $this->emails('Zamówienie '.$order->number.' anulowane', 3);
        self::assertCount(1, $emails);
        self::assertStringContainsString('Anulowaliśmy Twoje zamówienie. Zwrócimy płatność.', $emails[0]['text']);
        self::assertStringNotContainsString('Nie otrzymaliśmy płatności', $emails[0]['text']);
        self::assertSame(0, $this->rows('failed'));
    }

    public function testAReturnedOrderGetsOneEmail(): void
    {
        $order = $this->placeAndPay();
        $this->changeStatus($order->orderId, StaffTransition::Ship, 2, null);
        $this->changeStatus($order->orderId, StaffTransition::Return, 3, 'Paczka wróciła');

        $this->consumeOutboxAndEvents(8);
        $this->redeliver(new OrderReturned($order->orderId, $this->clock->now));

        $emails = $this->emails('Zamówienie '.$order->number.' wróciło do nas', 4);
        self::assertCount(1, $emails);
        self::assertStringContainsString('Zamówienie '.$order->number.' wróciło do nas. Zwrócimy płatność.', $emails[0]['text']);
        self::assertNotNull(OrderingFixture::notificationSentAt($this->connection(), $order->orderId, 'return_received'));
        self::assertSame(0, $this->rows('failed'));
    }

    private function placeAndPay(): OrderView
    {
        $order = $this->place();
        $paid = $this->bus()->dispatch(new MarkOrderPaid($order->orderId, Uuid::v7()->toRfc4122(), 12_300, 'PLN', $this->clock->now))->last(HandledStamp::class)?->getResult();
        self::assertSame(MarkOrderPaidResult::Paid, $paid);

        return $order;
    }

    private function changeStatus(string $orderId, StaffTransition $transition, int $version, ?string $note): void
    {
        $this->bus()->dispatch(new ChangeOrderStatus($orderId, $transition, $version, $note, 'kc-staff-1', 'anna'));
    }

    /**
     * At-least-once delivery: the broker hands the same event over again, after the first one was handled.
     */
    private function redeliver(object $event): void
    {
        $this->transport('events')->send(new Envelope($event, [new BusNameStamp('event.bus')]));
        $this->consume('events');
        // A failed handler would put the message back through the retry delay (100 ms ±10 %), so wait it out.
        usleep(300_000);
    }

    /**
     * @return list<array{subject: string, from: string, to: list<string>, text: string}>
     */
    private function emails(string $subject, int $expected = 2): array
    {
        // The confirmation of the placement, the payment email when paid, and the email under test.
        $messages = $this->inbox->awaitMessages($expected);

        return array_values(array_filter($messages, static fn (array $message): bool => $message['subject'] === $subject));
    }

    private function version(string $orderId): int
    {
        $version = $this->connection()->fetchOne('SELECT version FROM ordering_order WHERE id = ?', [$orderId]);
        self::assertIsInt($version);

        return $version;
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
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => $messages, '--time-limit' => 10, '--sleep' => 0.05]);
        $tester->assertCommandIsSuccessful();
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}

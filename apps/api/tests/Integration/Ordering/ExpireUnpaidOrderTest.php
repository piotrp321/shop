<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Inventory\Application\Command\ReservationNotFound;
use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Infrastructure\SystemClock;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * ExpireUnpaidOrder on shop_test with the Doctrine transports (the `test_messaging` kernel; rolled back after each
 * test): the cancellation releases the reservation of the order (Inventory, nested) and writes OrderCancelled to the
 * outbox in one transaction, a second delivery changes nothing, and the delayed message placed with the order waits
 * in the database through a restart of the worker. The clock of Ordering is replaced to stand after the expiry; the
 * transport compares available_at with the system time, so the restart test moves available_at by SQL.
 */
final class ExpireUnpaidOrderTest extends MessagingKernelTestCase
{
    private Connection $db;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connection();
        $this->replaceClock();
    }

    public function testTheCancellationReleasesTheReservationAndWritesOrderCancelled(): void
    {
        [$shoes, $order] = $this->placeOrder();
        self::assertSame([5, 2], OrderingFixture::stock($this->db, $shoes));
        $this->clockAt($order->order->paymentDeadline->modify('+5 minutes'));

        $this->deliver($order->order->orderId);

        self::assertSame(['cancelled', 'payment_timeout'], $this->db->fetchNumeric('SELECT status, cancellation_reason FROM ordering_order WHERE id = ?', [$order->order->orderId]));
        self::assertSame([5, 0], OrderingFixture::stock($this->db, $shoes), 'reserved decreases by the units of the order');
        self::assertSame('released', $this->db->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$order->order->orderId]));
        self::assertSame(1, $this->cancelledEvents($order->order->orderId));
    }

    public function testTheMessageDeliveredTwiceChangesNothingTheSecondTime(): void
    {
        [$shoes, $order] = $this->placeOrder();
        $this->clockAt($order->order->paymentDeadline->modify('+5 minutes'));
        $this->deliver($order->order->orderId);
        $after = $this->state($order->order->orderId, $shoes);

        $this->deliver($order->order->orderId);

        self::assertSame($after, $this->state($order->order->orderId, $shoes));
        self::assertSame([5, 0], OrderingFixture::stock($this->db, $shoes), 'reserved changed once');
        self::assertSame(1, $this->cancelledEvents($order->order->orderId));
    }

    public function testTheMessageSurvivesARestartOfTheWorker(): void
    {
        [$shoes, $order] = $this->placeOrder();
        $orderId = $order->order->orderId;
        self::assertSame(1, $this->rows('scheduled'));

        // The first worker runs before the moment of the message and ends without handling it.
        $this->consume('scheduled', 1);
        self::assertSame('new', $this->db->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame(1, $this->rows('scheduled'));
        self::assertNull($this->db->fetchOne("SELECT delivered_at FROM messenger_messages WHERE queue_name = 'scheduled'"));

        // Time passes: the message becomes available and the clock stands after the expiry.
        $this->db->executeStatement("UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - interval '1 second' WHERE queue_name = 'scheduled'");
        self::bootMessagingKernel();
        $this->db = $this->connection();
        $this->replaceClock();
        $this->clockAt($order->order->paymentDeadline->modify('+5 minutes'));

        // The second worker, a new process, cancels the order.
        $this->consume('scheduled');

        self::assertSame('cancelled', $this->db->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame([5, 0], OrderingFixture::stock($this->db, $shoes));
        self::assertSame(0, $this->rows('scheduled'), 'the message was acknowledged');
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(1, $this->cancelledEvents($orderId));
    }

    public function testAMessageThatCameTooEarlyIsStoredAgainForASecondAfterTheExpiry(): void
    {
        [$shoes, $order] = $this->placeOrder();
        $orderId = $order->order->orderId;
        $this->db->executeStatement("UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - interval '1 second' WHERE queue_name = 'scheduled'");
        $this->clockAt($order->order->paymentDeadline->modify('+4 minutes'));

        $this->consume('scheduled');

        self::assertSame('new', $this->db->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame([5, 2], OrderingFixture::stock($this->db, $shoes));
        self::assertSame(1, $this->rows('scheduled'), 'the delivered message was acknowledged and a new one stored');
        $available = $this->db->fetchOne("SELECT EXTRACT(EPOCH FROM available_at)::bigint FROM messenger_messages WHERE queue_name = 'scheduled'");
        self::assertIsInt($available);
        $due = $order->order->paymentDeadline->modify('+5 minutes +1 second')->getTimestamp();
        self::assertContains($available, [$due, $due + 1]);
    }

    public function testAReservationInventoryDoesNotKnowIsAFailureThatChangesNothing(): void
    {
        [, $order] = $this->placeOrder();
        $orderId = $order->order->orderId;
        $this->db->executeStatement('DELETE FROM inventory_reservation_line WHERE reservation_key = ?', [$orderId]);
        $this->db->executeStatement('DELETE FROM inventory_reservation WHERE reservation_key = ?', [$orderId]);
        $this->clockAt($order->order->paymentDeadline->modify('+5 minutes'));

        try {
            $this->deliver($orderId);
            self::fail('Expected ReservationNotFound.');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(ReservationNotFound::class, array_values($failed->getWrappedExceptions())[0]);
        }

        self::assertSame('new', $this->db->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame(0, $this->cancelledEvents($orderId));
    }

    public function testAnUnknownOrderIsHandledWithoutAChange(): void
    {
        $before = $this->rows('outbox');

        $this->deliver('0192f0c4-7c1a-7c4e-9a6e-0000000000ff');

        self::assertSame($before, $this->rows('outbox'));
        self::assertSame(0, $this->rows('scheduled'));
    }

    /**
     * @return array{string, PlacedOrder} the variant (5 units, 2 reserved by the order) and the placed order
     */
    private function placeOrder(): array
    {
        [$shoes] = OrderingFixture::variant($this->db, 10_000, 5);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$shoes, 2]]);
        $command = new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', 2 * 12_300, 'key-'.$cart);
        $placed = $this->bus()->dispatch($command)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PlacedOrder::class, $placed);

        return [$shoes, $placed];
    }

    /**
     * The message as the worker hands it to the bus: received from `scheduled`, so it is handled, not sent again.
     */
    private function deliver(string $orderId): void
    {
        $this->bus()->dispatch(new Envelope(new ExpireUnpaidOrder($orderId), [new ReceivedStamp('scheduled')]));
    }

    /**
     * The clock of Ordering in the container just booted, before any handler uses it: the system time to the second
     * until a test moves it.
     */
    private function replaceClock(): void
    {
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('@'.time()));
        self::getContainer()->set(SystemClock::class, $this->clock);
    }

    private function clockAt(\DateTimeImmutable $now): void
    {
        $this->clock->now = $now;
    }

    private function cancelledEvents(string $orderId): int
    {
        return OrderingFixture::count(
            $this->db,
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0 AND position(? IN body) > 0",
            // The body escapes the backslashes of the class name.
            [addslashes(OrderCancelled::class), $orderId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $orderId, string $variant): array
    {
        return [
            'order' => $this->db->fetchAssociative('SELECT status, cancelled_at, version FROM ordering_order WHERE id = ?', [$orderId]),
            'stock' => OrderingFixture::stock($this->db, $variant),
            'stock version' => $this->db->fetchOne('SELECT version FROM inventory_stock_item WHERE variant_id = ?', [$variant]),
            'reservation' => $this->db->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]),
            'movements' => OrderingFixture::count($this->db, 'SELECT COUNT(*) FROM inventory_stock_movement'),
            'outbox' => $this->rows('outbox'),
            'scheduled' => $this->rows('scheduled'),
        ];
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}

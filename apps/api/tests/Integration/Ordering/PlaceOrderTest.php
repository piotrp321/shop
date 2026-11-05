<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\OrderPlaced;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * PlaceOrder on command.bus on shop_test with the Doctrine outbox (the `test_messaging` kernel; rolled back after each
 * test): the order, its lines, the reservation, the emptied cart and the outbox row are written together, and any
 * failure (a shortage of one line, a failed write of the order or of the outbox row) leaves the database as it was.
 */
final class PlaceOrderTest extends MessagingKernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connection();
    }

    public function testASuccessfulPlacementWritesTheOrderTheReservationTheEmptyCartAndTheOutboxRow(): void
    {
        [$shoes] = OrderingFixture::variant($this->db, 10_000, 5);
        [$map] = OrderingFixture::variant($this->db, 1_000, 5);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$shoes, 2], [$map, 1]]);

        $placed = $this->place($cart, 2 * 12_300 + 1_230);

        self::assertFalse($placed->replayed);
        $orderId = $placed->order->orderId;
        self::assertSame(1, OrderingFixture::count($this->db, "SELECT COUNT(*) FROM ordering_order WHERE id = ? AND status = 'new' AND number = ? AND total_gross = ?", [$orderId, $placed->order->number, 25_830]));
        self::assertMatchesRegularExpression('/^R-\d{6,}$/', $placed->order->number);
        self::assertSame(self::sorted([$shoes => 2, $map => 1]), OrderingFixture::orderLines($this->db, $orderId));
        self::assertSame(self::sorted([$shoes => 2, $map => 1]), OrderingFixture::reservation($this->db, $orderId), 'the reservation has the order id as its key and the same lines');
        self::assertSame('active', $this->db->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]));
        self::assertSame([5, 2], OrderingFixture::stock($this->db, $shoes));
        self::assertSame([], CartFixture::lines($this->db, $cart), 'the cart is empty');
        self::assertSame('open', $this->db->fetchOne('SELECT status FROM cart_cart WHERE id = ?', [$cart]));
        self::assertSame(1, $this->rows('outbox'));
        $body = $this->db->fetchOne("SELECT body FROM messenger_messages WHERE queue_name = 'outbox'");
        self::assertIsString($body);
        // The body escapes the backslashes of the class name.
        self::assertStringContainsString(addslashes(OrderPlaced::class), $body);
        self::assertStringContainsString($orderId, $body);
    }

    public function testAReplayGivesTheSameOrderWithoutASecondReservationOrEvent(): void
    {
        [$shoes] = OrderingFixture::variant($this->db, 10_000, 5);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$shoes, 1]]);
        $first = $this->place($cart, 12_300);
        CartFixture::line($this->db, $cart, $shoes, 3);

        $replayed = $this->place($cart, 12_300);

        self::assertTrue($replayed->replayed);
        self::assertSame($first->order->orderId, $replayed->order->orderId);
        self::assertEquals($first->order->lines, $replayed->order->lines);
        self::assertSame([5, 1], OrderingFixture::stock($this->db, $shoes));
        self::assertSame([[$shoes, 3]], CartFixture::lines($this->db, $cart), 'the line added after the order stays');
        self::assertSame(1, $this->rows('outbox'));
    }

    public function testAShortageOfTheLastOfThreeLinesLeavesEverythingAsItWas(): void
    {
        [$a] = OrderingFixture::variant($this->db, 10_000, 5);
        [$b] = OrderingFixture::variant($this->db, 2_000, 5, reserved: 1);
        [$c] = OrderingFixture::variant($this->db, 1_000, 3, reserved: 2);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$a, 1], [$b, 2], [$c, 2]]);
        $before = $this->snapshot($cart, [$a, $b, $c]);

        $failure = $this->failure($cart, 12_300 + 2 * 2_460 + 2 * 1_230);

        self::assertInstanceOf(InsufficientStockForOrder::class, $failure);
        self::assertSame('Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.', $failure->getMessage());
        self::assertSame($before, $this->snapshot($cart, [$a, $b, $c]));
    }

    public function testAFailedWriteOfTheOrderLeavesEverythingAsItWas(): void
    {
        [$a] = OrderingFixture::variant($this->db, 10_000, 5);
        [$b] = OrderingFixture::variant($this->db, 2_000, 5);
        [$c] = OrderingFixture::variant($this->db, 1_000, 5);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$a, 1], [$b, 2], [$c, 2]]);
        $before = $this->snapshot($cart, [$a, $b, $c]);
        $this->refuseInsertsInto('ordering_order');

        $failure = $this->failure($cart, 12_300 + 2 * 2_460 + 2 * 1_230);

        self::assertStringContainsString('forced failure', $failure->getMessage());
        self::assertSame($before, $this->snapshot($cart, [$a, $b, $c]));
    }

    public function testAFailedWriteOfTheOutboxRowLeavesEverythingAsItWas(): void
    {
        [$a] = OrderingFixture::variant($this->db, 10_000, 5);
        [$b] = OrderingFixture::variant($this->db, 2_000, 5);
        [$c] = OrderingFixture::variant($this->db, 1_000, 5);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$a, 1], [$b, 2], [$c, 2]]);
        $before = $this->snapshot($cart, [$a, $b, $c]);
        $this->refuseInsertsInto('messenger_messages');

        $failure = $this->failure($cart, 12_300 + 2 * 2_460 + 2 * 1_230);

        self::assertStringContainsString('forced failure', $failure->getMessage().' '.$failure->getPrevious()?->getMessage());
        self::assertSame($before, $this->snapshot($cart, [$a, $b, $c]));
    }

    public function testOrderPlacedSurvivesTheSerializerOfTheEventsTransport(): void
    {
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $event = new OrderPlaced('0192f0c4-7c1a-7c4e-9a6e-0000000000aa', new \DateTimeImmutable('2026-11-05 12:00:00+00:00'));

        $decoded = $serializer->decode($serializer->encode(new Envelope($event)))->getMessage();

        self::assertInstanceOf(OrderPlaced::class, $decoded);
        self::assertSame($event->orderId, $decoded->orderId);
        self::assertEquals($event->occurredAt(), $decoded->occurredAt());
    }

    private function place(string $cart, int $expectedTotalGross): PlacedOrder
    {
        $result = $this->bus()->dispatch(self::command($cart, $expectedTotalGross))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PlacedOrder::class, $result);

        return $result;
    }

    private function failure(string $cart, int $expectedTotalGross): \Throwable
    {
        try {
            $this->bus()->dispatch(self::command($cart, $expectedTotalGross));
        } catch (HandlerFailedException $failed) {
            $wrapped = array_values($failed->getWrappedExceptions());
            self::assertCount(1, $wrapped);

            return $wrapped[0];
        }
        self::fail('Expected the placement to fail.');
    }

    private static function command(string $cart, int $expectedTotalGross): PlaceOrder
    {
        return new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', $expectedTotalGross, 'key-'.$cart);
    }

    /**
     * Everything a placement could change: the cart (its lines and expiry), the stocks of the variants, and every
     * reservation, order, order line and outbox row.
     *
     * @param list<string> $variants
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $cart, array $variants): array
    {
        return [
            'cart lines' => CartFixture::lines($this->db, $cart),
            'cart' => $this->db->fetchAssociative('SELECT status, updated_at, expires_at FROM cart_cart WHERE id = ?', [$cart]),
            'stocks' => array_map(fn (string $variant): array => OrderingFixture::stock($this->db, $variant), $variants),
            'stock versions' => $this->db->fetchFirstColumn('SELECT version FROM inventory_stock_item WHERE variant_id IN (?, ?, ?) ORDER BY variant_id', $variants),
            'reservations' => OrderingFixture::count($this->db, 'SELECT COUNT(*) FROM inventory_reservation'),
            'reservation lines' => OrderingFixture::count($this->db, 'SELECT COUNT(*) FROM inventory_reservation_line'),
            'stock movements' => OrderingFixture::count($this->db, 'SELECT COUNT(*) FROM inventory_stock_movement'),
            'orders' => OrderingFixture::count($this->db, 'SELECT COUNT(*) FROM ordering_order'),
            'order lines' => OrderingFixture::count($this->db, 'SELECT COUNT(*) FROM ordering_order_line'),
            'outbox rows' => $this->rows('outbox'),
        ];
    }

    /**
     * A trigger (rolled back with the test) that makes every insert into the table fail.
     */
    private function refuseInsertsInto(string $table): void
    {
        $this->db->executeStatement("CREATE FUNCTION test_refuse_insert() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure'; END \$\$");
        $this->db->executeStatement(\sprintf('CREATE TRIGGER test_refuse_insert BEFORE INSERT ON %s FOR EACH ROW EXECUTE FUNCTION test_refuse_insert()', $table));
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * @param array<string, int> $lines
     *
     * @return array<string, int>
     */
    private static function sorted(array $lines): array
    {
        ksort($lines);

        return $lines;
    }
}

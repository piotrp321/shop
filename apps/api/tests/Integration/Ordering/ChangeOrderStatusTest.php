<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Application\Command\CompleteRefund;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderReturned;
use App\Ordering\Domain\OrderShipped;
use App\Ordering\Domain\OrderTransitionNotAllowed;
use App\Ordering\Domain\RefundNotPending;
use App\Ordering\Domain\StaffTransition;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * ChangeOrderStatus and CompleteRefund on shop_test with the Doctrine transports (the `test_messaging` kernel; rolled
 * back after each test): a shipment commits the reservation of the order (Inventory, nested), a cancellation releases
 * it, each with its history entry and its event in the outbox in one transaction; a stale version changes nothing.
 */
final class ChangeOrderStatusTest extends MessagingKernelTestCase
{
    use Factories;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connection();
    }

    public function testShipCommitsTheReservationWithTheHistoryEntryAndOrderShippedInTheOutbox(): void
    {
        [$shoes, $orderId] = $this->paidOrder();

        $view = $this->change($orderId, StaffTransition::Ship, 2, 'Kurier DPD');

        self::assertSame(['shipped', 3], [$view->status, $view->version]);
        self::assertSame(['shipped', 3], $this->db->fetchNumeric('SELECT status, version FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame([3, 0], OrderingFixture::stock($this->db, $shoes), 'physical and reserved decrease by the units of the order');
        self::assertSame('committed', $this->db->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]));
        self::assertSame(['paid', 'shipped', 'staff', 'kc-staff-1', 'anna', 'Kurier DPD'], $this->lastHistoryEntry($orderId));
        self::assertSame(1, $this->events(OrderShipped::class, $orderId));
    }

    public function testCancelReleasesTheReservationCreatesTheRefundAndWritesOrderCancelled(): void
    {
        [$shoes, $orderId] = $this->paidOrder();

        $view = $this->change($orderId, StaffTransition::Cancel, 2, 'Klient zrezygnował');

        self::assertSame(['cancelled', 'staff'], [$view->status, $view->cancellationReason]);
        self::assertSame(['cancelled', 'staff', 24_600], $this->db->fetchNumeric('SELECT status, cancellation_reason, refund_amount FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame([5, 0], OrderingFixture::stock($this->db, $shoes), 'reserved decreases, physical stays');
        self::assertSame('released', $this->db->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]));
        self::assertSame(['paid', 'cancelled', 'staff', 'kc-staff-1', 'anna', 'Klient zrezygnował'], $this->lastHistoryEntry($orderId));
        self::assertSame(1, $this->events(OrderCancelled::class, $orderId));
    }

    public function testDeliverAndReturnLeaveTheStockAsItIs(): void
    {
        [$shoes, $orderId] = $this->paidOrder();
        $this->change($orderId, StaffTransition::Ship, 2, null);

        $this->change($orderId, StaffTransition::Deliver, 3, null);
        $view = $this->change($orderId, StaffTransition::Return, 4, 'Zwrot towaru');

        self::assertSame(['returned', 5], [$view->status, $view->version]);
        self::assertSame([3, 0], OrderingFixture::stock($this->db, $shoes), 'the returned goods do not go back on the stock by themselves');
        self::assertSame(1, $this->events(OrderReturned::class, $orderId));
        self::assertSame(5, OrderingFixture::count($this->db, 'SELECT COUNT(*) FROM ordering_order_status_change WHERE order_id = ?', [$orderId]));
    }

    public function testAStaleVersionIsAConflictWithoutAnyChange(): void
    {
        [$shoes, $orderId] = $this->paidOrder();
        $before = $this->state($orderId, $shoes);

        $this->expectFailure(OrderModifiedConcurrently::class, fn () => $this->change($orderId, StaffTransition::Ship, 1, null));
        $this->expectFailure(OrderModifiedConcurrently::class, fn () => $this->change($orderId, StaffTransition::Cancel, 3, 'Brak towaru'));

        self::assertSame($before, $this->state($orderId, $shoes));
    }

    public function testATransitionOutsideTheTableChangesNothing(): void
    {
        [$shoes, $orderId] = $this->paidOrder();
        $before = $this->state($orderId, $shoes);

        $this->expectFailure(OrderTransitionNotAllowed::class, fn () => $this->change($orderId, StaffTransition::Deliver, 2, null));

        self::assertSame($before, $this->state($orderId, $shoes));
    }

    public function testCompleteRefundOnceThenRefundNotPendingAndAStaleVersionIsAConflict(): void
    {
        [$shoes, $orderId] = $this->paidOrder();
        $this->change($orderId, StaffTransition::Cancel, 2, 'Brak towaru');

        $this->expectFailure(OrderModifiedConcurrently::class, fn () => $this->completeRefund($orderId, 2));
        $view = $this->completeRefund($orderId, 3);
        $after = $this->state($orderId, $shoes);
        $this->expectFailure(RefundNotPending::class, fn () => $this->completeRefund($orderId, 4));

        self::assertSame(4, $view->version);
        self::assertNotNull($view->refund?->completedAt);
        self::assertSame(['kc-staff-2', 'bartek', 'Przelew wysłany'], $this->db->fetchNumeric('SELECT refund_completed_by_staff_id, refund_completed_by_username, refund_note FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame($after, $this->state($orderId, $shoes));
    }

    public function testCancelAndReturnByStaffKeepTheUsageOfTheDiscountCode(): void
    {
        // Q1: the refund of a paid order is after the discount already, so its usage stays.
        $code = DiscountCodeFactory::createOne(['code' => 'STAFF-Q1', 'value' => 10]);
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        new DoctrineDiscountCodeRepository($entityManager)->save($code);
        [, $cancelled] = $this->paidOrder('STAFF-Q1');
        [, $returned] = $this->paidOrder('STAFF-Q1');

        $this->change($cancelled, StaffTransition::Cancel, 2, 'Klient zrezygnował');
        $this->change($returned, StaffTransition::Ship, 2, null);
        $this->change($returned, StaffTransition::Return, 3, 'Zwrot towaru');

        self::assertSame(
            [[$cancelled, true], [$returned, true]],
            $this->db->fetchAllNumeric('SELECT order_id, released_at IS NULL FROM pricing_discount_code_usage WHERE code_id = ? ORDER BY order_id = ? DESC', [$code->id()->toString(), $cancelled]),
        );
    }

    /**
     * @return array{string, string} the variant (5 units, 2 reserved by the order) and the id of the order, placed and
     *                               paid (version 2), with the discount code if one is given (10 %)
     */
    private function paidOrder(?string $discountCode = null): array
    {
        [$shoes] = OrderingFixture::variant($this->db, 10_000, 5);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$shoes, 2]]);
        $total = null === $discountCode ? 2 * 12_300 : 2 * 12_300 - 2_460;
        $placed = $this->dispatch(new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', $total, 'key-'.$cart, $discountCode));
        self::assertInstanceOf(PlacedOrder::class, $placed);
        $orderId = $placed->order->orderId;
        $this->dispatch(new MarkOrderPaid($orderId, Uuid::v7()->toRfc4122(), $total, 'PLN', new \DateTimeImmutable()));
        self::assertSame([5, 2], OrderingFixture::stock($this->db, $shoes));

        return [$shoes, $orderId];
    }

    private function change(string $orderId, StaffTransition $transition, int $version, ?string $note): BackofficeOrderView
    {
        $view = $this->dispatch(new ChangeOrderStatus($orderId, $transition, $version, $note, 'kc-staff-1', 'anna'));
        self::assertInstanceOf(BackofficeOrderView::class, $view);

        return $view;
    }

    private function completeRefund(string $orderId, int $version): BackofficeOrderView
    {
        $view = $this->dispatch(new CompleteRefund($orderId, $version, 'Przelew wysłany', 'kc-staff-2', 'bartek'));
        self::assertInstanceOf(BackofficeOrderView::class, $view);

        return $view;
    }

    private function dispatch(object $command): mixed
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function expectFailure(string $expected, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected '.$expected);
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf($expected, array_values($failed->getWrappedExceptions())[0]);
        }
    }

    /**
     * @return list<mixed> from, to, actor, staff id, staff name and note of the newest entry of the history
     */
    private function lastHistoryEntry(string $orderId): array
    {
        $row = $this->db->fetchNumeric('SELECT from_status, to_status, actor_type, staff_id, staff_username, note FROM ordering_order_status_change WHERE order_id = ? ORDER BY position DESC LIMIT 1', [$orderId]);
        self::assertIsArray($row);

        return $row;
    }

    private function events(string $class, string $orderId): int
    {
        return OrderingFixture::count(
            $this->db,
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0 AND position(? IN body) > 0",
            // The body escapes the backslashes of the class name.
            [addslashes($class), $orderId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $orderId, string $variant): array
    {
        return [
            'order' => $this->db->fetchAssociative('SELECT * FROM ordering_order WHERE id = ?', [$orderId]),
            'history' => $this->db->fetchAllAssociative('SELECT * FROM ordering_order_status_change WHERE order_id = ? ORDER BY position', [$orderId]),
            'stock' => OrderingFixture::stock($this->db, $variant),
            'reservation' => $this->db->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]),
            'outbox' => $this->rows('outbox'),
        ];
    }
}

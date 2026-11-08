<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Application\Command\ChangeOrderStatusHandler;
use App\Ordering\Application\Command\CompleteRefund;
use App\Ordering\Application\Command\CompleteRefundHandler;
use App\Ordering\Application\Port\DiscountCodeRedemptions;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Application\View\RefundView;
use App\Ordering\Application\View\StatusChangeView;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderReturned;
use App\Ordering\Domain\OrderShipped;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\OrderTransitionNotAllowed;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\RefundNotPending;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryStockReservations;
use App\Tests\Unit\Ordering\Journal;
use App\Tests\Unit\Ordering\JournalingEventPublisher;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\TestCase;

/**
 * ChangeOrderStatus and CompleteRefund, the commands of staff, with in-memory ports. The transaction is the bus's;
 * these tests check the steps: the lock of the order first, then the version the staff member saw, the transition,
 * the reservation (committed by a shipment, released by a cancellation) before the save, the events, and the view
 * of the order as saved.
 */
final class ChangeOrderStatusHandlerTest extends TestCase
{
    private const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string NOW = '2026-11-06 09:00:00+00:00';
    /** The version of the paid example order: placed (1), paid (2). */
    private const int PAID_VERSION = 2;

    private Journal $journal;
    private VersionedOrders $orders;
    private InMemoryStockReservations $stock;
    private JournalingEventPublisher $events;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->journal = new Journal();
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->orders->changeElsewhere(self::orderId(), static fn (Order $order) => $order->markPaid(PaymentId::fromString(self::PAYMENT), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00')));
        $this->orders->saved = [];
        $this->stock = new InMemoryStockReservations($this->journal);
        $this->events = new JournalingEventPublisher($this->journal);
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable(self::NOW));
    }

    public function testShipLocksTheOrderCommitsTheReservationBeforeTheSaveAndReturnsTheSavedOrder(): void
    {
        $view = $this->change(StaffTransition::Ship, self::PAID_VERSION, 'Kurier');

        self::assertSame([OrderExamples::ORDER], $this->orders->locked);
        self::assertSame([OrderExamples::ORDER], $this->stock->committed, 'the reservation has the order id as its key');
        self::assertSame([], $this->stock->released);
        self::assertSame([OrderExamples::ORDER], $this->orders->saved);
        self::assertSame(['commit', 'publish'], $this->journal->calls, 'the reservation first, so the flush of the nested command of Inventory has nothing of Ordering');
        self::assertEquals([new OrderShipped(OrderExamples::ORDER, $this->clock->now)], $this->events->published);

        self::assertSame(OrderStatus::Shipped, $this->order()->status());
        self::assertSame('shipped', $view->status);
        self::assertSame(self::PAID_VERSION + 1, $view->version, 'the version of the saved order');
        self::assertEquals($this->clock->now, $view->shippedAt);
        self::assertSame(['deliver', 'return'], $view->statusTransitions);
        self::assertEquals(new StatusChangeView('paid', 'shipped', $this->clock->now, 'staff', 'kc-staff-1', 'anna', 'Kurier'), $view->history[2]);
        self::assertCount(3, $view->history);
        self::assertNull($view->refund);
    }

    public function testCancelReleasesTheReservationAndCreatesTheRefund(): void
    {
        $view = $this->change(StaffTransition::Cancel, self::PAID_VERSION, 'Klient zrezygnował');

        self::assertSame([OrderExamples::ORDER], $this->stock->released);
        self::assertSame([], $this->stock->committed);
        self::assertSame(['release', 'publish'], $this->journal->calls);
        self::assertEquals([new OrderCancelled(OrderExamples::ORDER, 'staff', $this->clock->now)], $this->events->published);
        self::assertSame(['cancelled', 'staff'], [$view->status, $view->cancellationReason]);
        self::assertEquals(new RefundView(OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-20 09:00:00+00:00'), false, null, null, null, null), $view->refund);
        self::assertSame([], $view->statusTransitions);
        self::assertSame(self::PAYMENT, $view->paymentId);
    }

    public function testCancelAndReturnByStaffDoNotReleaseTheUsageOfADiscountCode(): void
    {
        // Q1: the refund of a paid order is after the discount already, so the usage stays. The handler has no way
        // to release one: it does not depend on the port of the redemptions.
        $parameters = new \ReflectionMethod(ChangeOrderStatusHandler::class, '__construct')->getParameters();
        $types = array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $parameters);

        self::assertNotContains(DiscountCodeRedemptions::class, $types);
    }

    public function testDeliverAndReturnLeaveTheReservationAlone(): void
    {
        $this->change(StaffTransition::Ship, self::PAID_VERSION, null);
        $this->stock->committed = [];
        $this->journal->calls = [];
        $this->events->published = [];

        $delivered = $this->change(StaffTransition::Deliver, self::PAID_VERSION + 1, null);
        $returned = $this->change(StaffTransition::Return, self::PAID_VERSION + 2, 'Zwrot towaru');

        self::assertSame(['delivered', 'returned'], [$delivered->status, $returned->status]);
        self::assertSame([], $this->stock->committed);
        self::assertSame([], $this->stock->released);
        self::assertSame(['publish'], $this->journal->calls, 'only the return has an event');
        self::assertEquals([new OrderReturned(OrderExamples::ORDER, $this->clock->now)], $this->events->published);
        self::assertNotNull($returned->refund);
    }

    public function testAStaleVersionIsAConflictWithoutAnyChange(): void
    {
        try {
            $this->change(StaffTransition::Ship, self::PAID_VERSION - 1, null);
            self::fail('Expected OrderModifiedConcurrently.');
        } catch (OrderModifiedConcurrently) {
            $this->addToAssertionCount(1);
        }

        $this->assertNothingChanged();
        self::assertSame([OrderExamples::ORDER], $this->orders->locked, 'the version is compared under the lock');
    }

    public function testATransitionOutsideTheTableChangesNothing(): void
    {
        try {
            $this->change(StaffTransition::Return, self::PAID_VERSION, 'Zwrot');
            self::fail('Expected OrderTransitionNotAllowed.');
        } catch (OrderTransitionNotAllowed) {
            $this->addToAssertionCount(1);
        }

        $this->assertNothingChanged();
    }

    public function testAnInvalidNoteChangesNothing(): void
    {
        try {
            $this->change(StaffTransition::Cancel, self::PAID_VERSION, '   ');
            self::fail('Expected InvalidOrderInput.');
        } catch (InvalidOrderInput) {
            $this->addToAssertionCount(1);
        }

        $this->assertNothingChanged();
    }

    public function testAFailureOfInventoryIsThrownAndNothingIsSaved(): void
    {
        $failure = new \RuntimeException('Reservation already released.');
        $this->stock->commitFailure = $failure;

        try {
            $this->change(StaffTransition::Ship, self::PAID_VERSION, null);
            self::fail('Expected the failure of the commit.');
        } catch (\RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }
        self::assertSame([], $this->orders->saved);
        self::assertSame([], $this->events->published);
        self::assertSame(OrderStatus::Paid, $this->order()->status());
    }

    public function testAnUnknownOrderOrAnIdThatIsNoUuidIsNotFound(): void
    {
        foreach (['0192f0c4-7c1a-7c4e-9a6e-0000000000ff', 'not-a-uuid'] as $orderId) {
            try {
                $this->changeHandler()(new ChangeOrderStatus($orderId, StaffTransition::Ship, 1, null, 'kc-staff-1', 'anna'));
                self::fail('Expected OrderNotFound for '.$orderId);
            } catch (OrderNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingChanged();
    }

    public function testCompleteRefundCompletesThePendingRefundOnce(): void
    {
        $this->change(StaffTransition::Cancel, self::PAID_VERSION, 'Brak towaru');
        $this->clock->now = new \DateTimeImmutable('2026-11-21 10:00:00+00:00');
        $this->orders->locked = [];
        $this->orders->saved = [];

        $view = $this->refundHandler()(new CompleteRefund(OrderExamples::ORDER, self::PAID_VERSION + 1, 'Przelew', 'kc-staff-2', 'bartek'));

        self::assertSame([OrderExamples::ORDER], $this->orders->locked);
        self::assertSame([OrderExamples::ORDER], $this->orders->saved);
        self::assertSame(self::PAID_VERSION + 2, $view->version);
        self::assertSame('cancelled', $view->status);
        self::assertEquals(new RefundView(OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-20 09:00:00+00:00'), false, $this->clock->now, 'kc-staff-2', 'bartek', 'Przelew'), $view->refund, 'a completed refund is not overdue');
        self::assertCount(3, $view->history, 'no history entry');

        try {
            $this->refundHandler()(new CompleteRefund(OrderExamples::ORDER, self::PAID_VERSION + 2, null, 'kc-staff-2', 'bartek'));
            self::fail('Expected RefundNotPending.');
        } catch (RefundNotPending) {
            $this->addToAssertionCount(1);
        }
        self::assertSame([OrderExamples::ORDER], $this->orders->saved, 'saved once');
    }

    public function testCompleteRefundWithAStaleVersionIsAConflict(): void
    {
        $this->change(StaffTransition::Cancel, self::PAID_VERSION, 'Brak towaru');
        $this->orders->saved = [];

        try {
            $this->refundHandler()(new CompleteRefund(OrderExamples::ORDER, self::PAID_VERSION, null, 'kc-staff-2', 'bartek'));
            self::fail('Expected OrderModifiedConcurrently.');
        } catch (OrderModifiedConcurrently) {
            $this->addToAssertionCount(1);
        }
        self::assertSame([], $this->orders->saved);
        self::assertTrue($this->order()->refund()?->isPending());
    }

    public function testThePendingRefundPastItsDueDateIsOverdueInTheView(): void
    {
        $this->change(StaffTransition::Cancel, self::PAID_VERSION, 'Brak towaru');

        self::assertTrue(BackofficeOrderView::of($this->order(), new \DateTimeImmutable('2026-11-20 09:00:01+00:00'))->refund?->overdue);
        self::assertFalse(BackofficeOrderView::of($this->order(), new \DateTimeImmutable('2026-11-20 09:00:00+00:00'))->refund?->overdue);
    }

    public function testTheViewHasTheWholeOrder(): void
    {
        $view = BackofficeOrderView::of($this->order(), $this->clock->now);

        self::assertSame([OrderExamples::ORDER, 'R-100001', 'paid', self::PAID_VERSION], [$view->orderId, $view->number, $view->status, $view->version]);
        self::assertSame(['jan@example.com', 'Jan Kowalski', '+48600100200'], [$view->email, $view->fullName, $view->phone]);
        self::assertSame(['Długa 1', '00-950', 'Warszawa', 'PL'], [$view->shippingAddress->street, $view->shippingAddress->postalCode, $view->shippingAddress->city, $view->shippingAddress->country]);
        self::assertCount(2, $view->lines);
        self::assertSame(OrderExamples::TOTAL, $view->totals->gross);
        self::assertEquals(
            [new \DateTimeImmutable(OrderExamples::PLACED_AT), new \DateTimeImmutable(OrderExamples::DEADLINE), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'), null, null, null, null],
            [$view->placedAt, $view->paymentDeadline, $view->paidAt, $view->shippedAt, $view->deliveredAt, $view->cancelledAt, $view->returnedAt],
        );
        self::assertNull($view->cancellationReason);
        self::assertEquals([
            new StatusChangeView(null, 'new', new \DateTimeImmutable(OrderExamples::PLACED_AT), 'customer', null, null, null),
            new StatusChangeView('new', 'paid', new \DateTimeImmutable('2026-11-05 12:10:05+00:00'), 'system', null, null, null),
        ], $view->history);
        self::assertSame(['ship', 'cancel'], $view->statusTransitions);
    }

    private function change(StaffTransition $transition, int $version, ?string $note): BackofficeOrderView
    {
        return $this->changeHandler()(new ChangeOrderStatus(OrderExamples::ORDER, $transition, $version, $note, 'kc-staff-1', 'anna'));
    }

    private function changeHandler(): ChangeOrderStatusHandler
    {
        return new ChangeOrderStatusHandler($this->orders, $this->stock, $this->events, $this->clock);
    }

    private function refundHandler(): CompleteRefundHandler
    {
        return new CompleteRefundHandler($this->orders, $this->clock);
    }

    private function order(): Order
    {
        return $this->orders->get(self::orderId());
    }

    private function assertNothingChanged(): void
    {
        self::assertSame([], $this->orders->saved);
        self::assertSame([], $this->stock->committed);
        self::assertSame([], $this->stock->released);
        self::assertSame([], $this->events->published);
        self::assertSame(OrderStatus::Paid, $this->order()->status());
        self::assertSame(self::PAID_VERSION, $this->order()->version());
    }

    private static function orderId(): OrderId
    {
        return OrderId::fromString(OrderExamples::ORDER);
    }
}

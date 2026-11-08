<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\ExpireUnpaidOrderHandler;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\PaymentId;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryDiscountCodeRedemptions;
use App\Tests\Unit\Ordering\InMemoryStockReservations;
use App\Tests\Unit\Ordering\InMemoryUnpaidOrderExpiry;
use App\Tests\Unit\Ordering\Journal;
use App\Tests\Unit\Ordering\JournalingEventPublisher;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\SpyLogger;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\TestCase;

/**
 * ExpireUnpaidOrder with in-memory ports. The transaction that releases the reservation and stores the cancelled
 * order together is the bus's; these tests check what the handler does: it releases only when the order is
 * cancelled now, schedules the message again when it came too early, and ignores what does not apply.
 */
final class ExpireUnpaidOrderHandlerTest extends TestCase
{
    private const string EXPIRY_DUE = '2026-11-05 12:35:00+00:00';

    private Journal $journal;
    private VersionedOrders $orders;
    private InMemoryStockReservations $stock;
    private InMemoryDiscountCodeRedemptions $redemptions;
    private InMemoryUnpaidOrderExpiry $expiry;
    private JournalingEventPublisher $events;
    private FixedOrderingClock $clock;
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->journal = new Journal();
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->stock = new InMemoryStockReservations($this->journal);
        $this->redemptions = new InMemoryDiscountCodeRedemptions($this->journal);
        $this->expiry = new InMemoryUnpaidOrderExpiry($this->journal);
        $this->events = new JournalingEventPublisher($this->journal);
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable(self::EXPIRY_DUE));
        $this->logger = new SpyLogger();
    }

    public function testAnUnpaidOrderIsCancelledAndItsReservationReleased(): void
    {
        $this->handle();

        $order = $this->order();
        self::assertSame(OrderStatus::Cancelled, $order->status());
        self::assertEquals($this->clock->now, $order->cancelledAt());
        self::assertSame([OrderExamples::ORDER], $this->stock->released, 'the reservation has the order id as its key');
        self::assertEquals([new OrderCancelled(OrderExamples::ORDER, 'payment_timeout', $this->clock->now)], $this->events->published);
        self::assertSame([], $this->expiry->scheduled);
        // The reservation and then the usage of a code are released before the order is written, so the flushes of the
        // nested commands of Inventory and Pricing have nothing of Ordering to write.
        self::assertSame(['release', 'release usage', 'publish'], $this->journal->calls);
        self::assertSame([OrderExamples::ORDER], $this->redemptions->released, 'released also for an order without a code (then without effect)');
        self::assertSame([OrderExamples::ORDER], $this->orders->saved);
        self::assertSame([OrderExamples::ORDER], $this->orders->locked, 'the order is locked before the release, like a change of staff');
    }

    public function testAMessageThatCameTooEarlyIsScheduledAgainASecondAfterTheExpiry(): void
    {
        $this->clock->now = new \DateTimeImmutable('2026-11-05 12:34:59+00:00');

        $this->handle();

        self::assertSame(OrderStatus::New, $this->order()->status());
        self::assertEquals([[OrderExamples::ORDER, new \DateTimeImmutable('2026-11-05 12:35:01+00:00')]], $this->expiry->scheduled);
        self::assertSame([], $this->stock->released);
        self::assertSame([], $this->redemptions->released);
        self::assertSame([], $this->events->published);
        self::assertSame([], $this->orders->saved);
    }

    public function testAPaidOrderIsNeitherCancelledNorReleased(): void
    {
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00')));
        $saves = \count($this->orders->saved);

        $this->handle();

        self::assertSame(OrderStatus::Paid, $this->order()->status());
        self::assertSame([], $this->stock->released);
        self::assertSame([], $this->redemptions->released, 'a paid order keeps the usage of its code');
        self::assertSame([], $this->expiry->scheduled);
        self::assertSame([], $this->events->published);
        self::assertCount($saves, $this->orders->saved, 'nothing saved');
    }

    public function testTheMessageDeliveredAgainChangesNothingAndReleasesOnce(): void
    {
        $this->handle();
        $this->clock->now = $this->clock->now->modify('+5 minutes');

        $this->handle();

        self::assertSame([OrderExamples::ORDER], $this->stock->released);
        self::assertSame([OrderExamples::ORDER], $this->redemptions->released, 'the usage is released once');
        self::assertCount(1, $this->events->published);
        self::assertSame([OrderExamples::ORDER], $this->orders->saved);
        self::assertEquals(new \DateTimeImmutable(self::EXPIRY_DUE), $this->order()->cancelledAt());
    }

    public function testAnUnknownOrderIsLoggedAndIgnored(): void
    {
        $unknown = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';

        $this->handler()(new ExpireUnpaidOrder($unknown));

        self::assertSame([], $this->stock->released);
        self::assertSame([], $this->expiry->scheduled);
        self::assertSame([], $this->events->published);
        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame('warning', $level);
        self::assertSame($unknown, $context['orderId'] ?? null);
    }

    public function testAnOrderPaidMeanwhileIsAVersionConflictForTheRetry(): void
    {
        $this->stock->whileReleasing = fn () => $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:30:00+00:00'), new \DateTimeImmutable('2026-11-05 12:34:59+00:00')));

        try {
            $this->handle();
            self::fail('Expected OrderModifiedConcurrently.');
        } catch (OrderModifiedConcurrently) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(OrderStatus::Paid, $this->order()->status(), 'the payment won; the bus rolls back the release');
        self::assertSame([], $this->events->published);

        // The retry reads the paid order and does nothing.
        $this->stock->whileReleasing = null;
        $this->stock->released = [];
        $this->handle();
        self::assertSame([], $this->stock->released);
        self::assertSame([], $this->events->published);
    }

    public function testAFailureOfTheReleaseIsThrownAndNothingIsSaved(): void
    {
        $failure = new \RuntimeException('Reservation not found.');
        $this->stock->releaseFailure = $failure;

        try {
            $this->handle();
            self::fail('Expected the failure of the release.');
        } catch (\RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }
        self::assertSame([], $this->orders->saved);
        self::assertSame([], $this->events->published);
        self::assertSame([], $this->redemptions->released, 'the usage is released only after the reservation');
        self::assertSame(OrderStatus::New, $this->order()->status());
    }

    private function handle(): void
    {
        $this->handler()(new ExpireUnpaidOrder(OrderExamples::ORDER));
    }

    private function handler(): ExpireUnpaidOrderHandler
    {
        return new ExpireUnpaidOrderHandler($this->orders, $this->stock, $this->redemptions, $this->expiry, $this->events, $this->clock, $this->logger);
    }

    private function order(): Order
    {
        return $this->orders->get(OrderId::fromString(OrderExamples::ORDER));
    }
}

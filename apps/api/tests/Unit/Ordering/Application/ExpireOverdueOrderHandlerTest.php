<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\ExpireOverdueOrder;
use App\Ordering\Application\Command\ExpireOverdueOrderHandler;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\PaymentId;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryDiscountCodeRedemptions;
use App\Tests\Unit\Ordering\InMemoryStockReservations;
use App\Tests\Unit\Ordering\InMemoryUnpaidOrderClaims;
use App\Tests\Unit\Ordering\InMemoryUnpaidOrderExpiry;
use App\Tests\Unit\Ordering\Journal;
use App\Tests\Unit\Ordering\JournalingEventPublisher;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\SpyLogger;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\TestCase;

/**
 * ExpireOverdueOrder, the synchronous command of ordering:expire-overdue (spec: docs/features/async-reliability/spec.md,
 * W5): the same expiry as the delayed ExpireUnpaidOrder (lock first, then the release of the reservation and the usage
 * of the code, the cancelled order, the claim and OrderCancelled), and it tells the console whether it cancelled the
 * order now.
 */
final class ExpireOverdueOrderHandlerTest extends TestCase
{
    private Journal $journal;
    private VersionedOrders $orders;
    private InMemoryStockReservations $stock;
    private InMemoryDiscountCodeRedemptions $redemptions;
    private InMemoryUnpaidOrderExpiry $expiry;
    private JournalingEventPublisher $events;
    private FixedOrderingClock $clock;
    private SpyLogger $logger;
    private InMemoryUnpaidOrderClaims $claims;

    protected function setUp(): void
    {
        $this->journal = new Journal();
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->stock = new InMemoryStockReservations($this->journal);
        $this->redemptions = new InMemoryDiscountCodeRedemptions($this->journal);
        $this->expiry = new InMemoryUnpaidOrderExpiry($this->journal);
        $this->events = new JournalingEventPublisher($this->journal);
        // 15 minutes after the expiry (the deadline 12:30 and the window of the webhooks), past the grace of the command.
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:50:00+00:00'));
        $this->logger = new SpyLogger();
        $this->claims = new InMemoryUnpaidOrderClaims($this->journal);
        $this->claims->claims = [OrderExamples::ORDER => ['address' => '203.0.113.7', 'identity' => 'email:jan@example.com', 'expiresAt' => new \DateTimeImmutable('2026-11-05 12:35:00+00:00')]];
    }

    public function testAnOverdueOrderIsCancelledAsTheDelayedMessageCancelsIt(): void
    {
        self::assertTrue($this->handle(), 'cancelled now');

        $order = $this->order();
        self::assertSame(OrderStatus::Cancelled, $order->status());
        self::assertEquals($this->clock->now, $order->cancelledAt());
        self::assertSame('payment_timeout', $order->cancellationReason()?->value);
        self::assertSame([OrderExamples::ORDER], $this->orders->locked, 'the order is locked first');
        self::assertSame(['release', 'release usage', 'unclaim', 'publish'], $this->journal->calls);
        self::assertSame([OrderExamples::ORDER], $this->stock->released);
        self::assertSame([OrderExamples::ORDER], $this->redemptions->released);
        self::assertSame([], $this->claims->claims);
        self::assertEquals([new OrderCancelled(OrderExamples::ORDER, 'payment_timeout', $this->clock->now)], $this->events->published);
        self::assertSame([], $this->expiry->scheduled, 'nothing is sent to a transport');
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->handle();
        $this->clock->now = $this->clock->now->modify('+5 minutes');

        self::assertFalse($this->handle());

        self::assertSame([OrderExamples::ORDER], $this->stock->released, 'released once');
        self::assertSame([OrderExamples::ORDER], $this->redemptions->released);
        self::assertCount(1, $this->events->published);
        self::assertSame([OrderExamples::ORDER], $this->orders->saved);
    }

    public function testAPaidOrderIsLeftAsItIs(): void
    {
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:29:00+00:00'), new \DateTimeImmutable('2026-11-05 12:31:00+00:00')));
        $saves = \count($this->orders->saved);

        self::assertFalse($this->handle());

        self::assertSame(OrderStatus::Paid, $this->order()->status());
        self::assertSame([], $this->stock->released);
        self::assertSame([], $this->redemptions->released);
        self::assertSame([], $this->events->published);
        self::assertCount($saves, $this->orders->saved);
    }

    public function testAnUnknownOrderIsLoggedAndIgnored(): void
    {
        $unknown = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';

        self::assertFalse(($this->handler())(new ExpireOverdueOrder($unknown)));

        self::assertSame([], $this->stock->released);
        self::assertSame([], $this->events->published);
        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0][0]);
        self::assertSame($unknown, $this->logger->records[0][2]['orderId'] ?? null);
    }

    public function testAnOrderPaidWhileReleasingIsAVersionConflict(): void
    {
        $this->stock->whileReleasing = fn () => $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:29:00+00:00'), new \DateTimeImmutable('2026-11-05 12:49:59+00:00')));

        $this->expectException(OrderModifiedConcurrently::class);

        $this->handle();
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
        self::assertSame(OrderStatus::New, $this->order()->status());
    }

    private function handle(): bool
    {
        return ($this->handler())(new ExpireOverdueOrder(OrderExamples::ORDER));
    }

    private function handler(): ExpireOverdueOrderHandler
    {
        return new ExpireOverdueOrderHandler($this->orders, $this->stock, $this->redemptions, $this->expiry, $this->events, $this->clock, $this->logger, $this->claims);
    }

    private function order(): Order
    {
        return $this->orders->get(OrderId::fromString(OrderExamples::ORDER));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\GetBackofficeOrder;
use App\Ordering\Application\Query\GetBackofficeOrderHandler;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\StaffActor;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GetBackofficeOrder, the detail of an order for the panel: the order as stored, read without a lock, with the refund
 * overdue against the clock.
 */
final class GetBackofficeOrderHandlerTest extends TestCase
{
    private const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string PAID_AT = '2026-11-05 12:10:00+00:00';
    private const string CANCELLED_AT = '2026-11-06 09:00:00+00:00';
    /** CANCELLED_AT + 14 days. */
    private const string REFUND_DUE = '2026-11-20 09:00:00+00:00';

    private VersionedOrders $orders;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable(self::REFUND_DUE));
    }

    public function testThePaidOrderWithItsPaymentVersionHistoryAndTransitions(): void
    {
        $this->pay();

        $view = $this->get(OrderExamples::ORDER);

        self::assertSame([OrderExamples::ORDER, 'R-100001', 'paid', 2], [$view->orderId, $view->number, $view->status, $view->version]);
        self::assertSame(['jan@example.com', 'Jan Kowalski', '+48600100200'], [$view->email, $view->fullName, $view->phone]);
        self::assertSame(['Długa 1', '00-950', 'Warszawa', 'PL'], [$view->shippingAddress->street, $view->shippingAddress->postalCode, $view->shippingAddress->city, $view->shippingAddress->country]);
        self::assertSame([['Buty Asfalt', '42', 2, 24_600], ['Czapka', '', 1, 2_000]], array_map(static fn ($line): array => [$line->productName, $line->variantLabel, $line->quantity, $line->lineGross], $view->lines));
        self::assertSame(OrderExamples::TOTAL, $view->totals->gross);
        self::assertEquals(new \DateTimeImmutable(OrderExamples::PLACED_AT), $view->placedAt);
        self::assertEquals(new \DateTimeImmutable(OrderExamples::DEADLINE), $view->paymentDeadline);
        self::assertEquals(new \DateTimeImmutable(self::PAID_AT), $view->paidAt);
        self::assertSame(self::PAYMENT, $view->paymentId);
        self::assertSame([[null, 'new', 'customer'], ['new', 'paid', 'system']], array_map(static fn ($change): array => [$change->from, $change->to, $change->actorType], $view->history), 'oldest first');
        self::assertNull($view->refund);
        self::assertSame(['ship', 'cancel'], $view->statusTransitions);
        self::assertSame([], $this->orders->locked, 'a read takes no lock');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function moments(): iterable
    {
        yield 'at the due date' => [self::REFUND_DUE, false];
        yield 'a second after it' => ['2026-11-20 09:00:01+00:00', true];
    }

    #[DataProvider('moments')]
    public function testThePendingRefundIsOverdueOnlyPastItsDueDate(string $now, bool $overdue): void
    {
        $this->pay();
        $this->cancel();
        $this->clock->now = new \DateTimeImmutable($now);

        $view = $this->get(OrderExamples::ORDER);

        self::assertSame(['cancelled', 'staff', 3], [$view->status, $view->cancellationReason, $view->version]);
        self::assertNotNull($view->refund);
        self::assertSame([OrderExamples::TOTAL, 'PLN', $overdue, null, null, null], [$view->refund->amount, $view->refund->currency, $view->refund->overdue, $view->refund->completedAt, $view->refund->completedByUsername, $view->refund->note]);
        self::assertEquals(new \DateTimeImmutable(self::REFUND_DUE), $view->refund->dueAt);
        self::assertSame([], $view->statusTransitions);
        self::assertSame(['staff', 'anna', 'Brak towaru'], [$view->history[2]->actorType, $view->history[2]->staffUsername, $view->history[2]->note]);
    }

    public function testACompletedRefundIsNeverOverdue(): void
    {
        $this->pay();
        $this->cancel();
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->completeRefund(StaffActor::of('kc-staff-2', 'bartek'), 'Przelew', new \DateTimeImmutable('2026-11-07 10:00:00+00:00')));
        $this->clock->now = new \DateTimeImmutable('2026-12-31 00:00:00+00:00');

        $refund = $this->get(OrderExamples::ORDER)->refund;

        self::assertNotNull($refund);
        self::assertFalse($refund->overdue);
        self::assertEquals(new \DateTimeImmutable('2026-11-07 10:00:00+00:00'), $refund->completedAt);
        self::assertSame(['kc-staff-2', 'bartek', 'Przelew'], [$refund->completedByStaffId, $refund->completedByUsername, $refund->note]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'an unknown order' => ['0192f0c4-7c1a-7c4e-9a6e-0000000000ff'];
        yield 'an id that is no UUID' => ['R-100001'];
        yield 'an empty id' => [''];
    }

    #[DataProvider('unknownIds')]
    public function testAnUnknownOrderIsNotFound(string $orderId): void
    {
        $this->expectException(OrderNotFound::class);

        $this->get($orderId);
    }

    private function pay(): void
    {
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->markPaid(PaymentId::fromString(self::PAYMENT), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable(self::PAID_AT), new \DateTimeImmutable(self::PAID_AT)));
    }

    private function cancel(): void
    {
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->cancel(StaffActor::of('kc-staff-1', 'anna'), 'Brak towaru', new \DateTimeImmutable(self::CANCELLED_AT)));
    }

    private function get(string $orderId): BackofficeOrderView
    {
        return new GetBackofficeOrderHandler($this->orders, $this->clock)(new GetBackofficeOrder($orderId));
    }
}

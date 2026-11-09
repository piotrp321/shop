<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\OrderAccess;
use App\Ordering\Application\Query\GetOrderForPayment;
use App\Ordering\Application\Query\GetOrderForPaymentHandler;
use App\Ordering\Application\Query\OrderForPayment;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\StaffActor;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GetOrderForPayment, what Payment learns of an order: only with access, and one answer (null) for every case without
 * it, so the caller cannot tell an unknown order from somebody else's.
 */
final class GetOrderForPaymentHandlerTest extends TestCase
{
    private const string CUSTOMER_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000cc';

    private VersionedOrders $orders;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $customerOrder = OrderExamples::placed(byCustomer: true);
        $this->orders->add(Order::reconstitute(
            id: OrderId::fromString(self::CUSTOMER_ORDER),
            number: $customerOrder->number(),
            owner: $customerOrder->owner(),
            accessToken: null,
            contact: $customerOrder->contact(),
            shippingAddress: $customerOrder->shippingAddress(),
            lines: $customerOrder->lines(),
            discount: null,
            status: $customerOrder->status(),
            idempotencyKey: $customerOrder->idempotencyKey(),
            requestFingerprint: $customerOrder->requestFingerprint(),
            placedAt: $customerOrder->placedAt(),
            paymentDeadline: $customerOrder->paymentDeadline(),
            paidAt: null,
            paymentId: null,
            cancelledAt: null,
            cancellationReason: null,
            shippedAt: null,
            deliveredAt: null,
            returnedAt: null,
            refund: null,
            history: $customerOrder->history(),
            version: 1,
        ));
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'));
    }

    public function testTheGuestGetsTheOrderByItsToken(): void
    {
        $order = $this->ask(OrderExamples::ORDER, OrderAccess::guest(OrderExamples::TOKEN));

        self::assertEquals(new OrderForPayment(OrderExamples::ORDER, 'R-100001', 'new', OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable(OrderExamples::DEADLINE), true), $order);
    }

    public function testTheCustomerGetsTheOrderById(): void
    {
        $order = $this->ask(self::CUSTOMER_ORDER, OrderAccess::customer(OrderExamples::CUSTOMER));

        self::assertSame(self::CUSTOMER_ORDER, $order?->orderId);
        self::assertTrue($order->payable);
    }

    public function testAnOrderIdInUpperCaseIsTheSameOrder(): void
    {
        self::assertSame(OrderExamples::ORDER, $this->ask(strtoupper(OrderExamples::ORDER), OrderAccess::guest(OrderExamples::TOKEN))?->orderId);
    }

    public function testAnOrderIsNotPayableFromItsDeadlineNorOncePaid(): void
    {
        $this->clock->now = new \DateTimeImmutable(OrderExamples::DEADLINE);
        $atDeadline = $this->ask(OrderExamples::ORDER, OrderAccess::guest(OrderExamples::TOKEN));
        self::assertNotNull($atDeadline);
        self::assertFalse($atDeadline->payable);
        self::assertSame('new', $atDeadline->statusForPayment);

        $this->clock->now = new \DateTimeImmutable('2026-11-05 12:10:00+00:00');
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:05:00+00:00'), new \DateTimeImmutable('2026-11-05 12:05:01+00:00')));
        $paid = $this->ask(OrderExamples::ORDER, OrderAccess::guest(OrderExamples::TOKEN));
        self::assertNotNull($paid);
        self::assertSame('paid', $paid->statusForPayment);
        self::assertFalse($paid->payable);
    }

    /**
     * @return iterable<string, array{\Closure(Order): mixed, string}> how staff take the paid order on, the status for
     *                                                                 the payment
     */
    public static function statusesAfterThePayment(): iterable
    {
        $staff = StaffActor::of('kc-staff-1', 'anna');
        $at = new \DateTimeImmutable('2026-11-06 09:00:00+00:00');
        yield 'shipped' => [static fn (Order $order) => $order->ship($staff, null, $at), 'paid'];
        yield 'delivered' => [static function (Order $order) use ($staff, $at): void {
            $order->ship($staff, null, $at);
            $order->deliver($staff, null, $at);
        }, 'paid'];
        yield 'returned' => [static function (Order $order) use ($staff, $at): void {
            $order->ship($staff, null, $at);
            $order->registerReturn($staff, 'Zwrot', $at);
        }, 'paid'];
        yield 'cancelled by staff' => [static fn (Order $order) => $order->cancel($staff, 'Brak towaru', $at), 'cancelled'];
    }

    /**
     * Payment knows only new, paid and cancelled: an order taken on after its payment is paid for the payment.
     *
     * @param \Closure(Order): mixed $takeOn
     */
    #[DataProvider('statusesAfterThePayment')]
    public function testTheStatusForThePaymentOfAnOrderTakenOnAfterItsPayment(\Closure $takeOn, string $statusForPayment): void
    {
        $id = OrderId::fromString(OrderExamples::ORDER);
        $this->orders->changeElsewhere($id, static fn (Order $order) => $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:05:00+00:00'), new \DateTimeImmutable('2026-11-05 12:05:01+00:00')));
        $this->orders->changeElsewhere($id, $takeOn);

        self::assertEquals(
            new OrderForPayment(OrderExamples::ORDER, 'R-100001', $statusForPayment, OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable(OrderExamples::DEADLINE), false),
            $this->ask(OrderExamples::ORDER, OrderAccess::guest(OrderExamples::TOKEN)),
        );
    }

    /**
     * @return iterable<string, array{string, OrderAccess}>
     */
    public static function withoutAccess(): iterable
    {
        yield 'unknown order' => ['0192f0c4-7c1a-7c4e-9a6e-0000000000ff', OrderAccess::guest(OrderExamples::TOKEN)];
        yield 'an id that is no UUID' => ['R-100001', OrderAccess::guest(OrderExamples::TOKEN)];
        yield 'an empty id' => ['', OrderAccess::guest(OrderExamples::TOKEN)];
        yield 'another token' => [OrderExamples::ORDER, OrderAccess::guest('eGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM')];
        yield 'an empty token' => [OrderExamples::ORDER, OrderAccess::guest('')];
        yield 'the token in upper case' => [OrderExamples::ORDER, OrderAccess::guest(strtoupper(OrderExamples::TOKEN))];
        yield 'a customer for a guest order' => [OrderExamples::ORDER, OrderAccess::customer(OrderExamples::CUSTOMER)];
        yield 'another customer' => [self::CUSTOMER_ORDER, OrderAccess::customer('kc-sub-2')];
        yield 'a guest token for a customer order' => [self::CUSTOMER_ORDER, OrderAccess::guest(OrderExamples::TOKEN)];
    }

    #[DataProvider('withoutAccess')]
    public function testEveryCaseWithoutAccessIsNull(string $orderId, OrderAccess $access): void
    {
        self::assertNull($this->ask($orderId, $access));
    }

    private function ask(string $orderId, OrderAccess $access): ?OrderForPayment
    {
        return new GetOrderForPaymentHandler($this->orders, $this->clock)(new GetOrderForPayment($orderId, $access));
    }
}

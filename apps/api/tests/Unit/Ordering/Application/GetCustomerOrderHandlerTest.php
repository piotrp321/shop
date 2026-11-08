<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\GetCustomerOrder;
use App\Ordering\Application\Query\GetCustomerOrderHandler;
use App\Ordering\Application\View\CustomerOrderView;
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
 * GetCustomerOrder, the detail of an order for its customer: the order as stored without a lock, nothing of the
 * history, the notes, the staff and the refund; one answer (null) for an unknown order, an order of another customer
 * or of a guest and an id that is no UUID.
 */
final class GetCustomerOrderHandlerTest extends TestCase
{
    private const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string PAID_AT = '2026-11-05 12:10:00+00:00';
    private const string CANCELLED_AT = '2026-11-06 09:00:00+00:00';

    private VersionedOrders $orders;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed(byCustomer: true));
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'));
    }

    public function testTheOrderOfTheCustomerWithItsLinesTotalsAddressAndDates(): void
    {
        $this->pay();

        $view = $this->get(OrderExamples::ORDER, OrderExamples::CUSTOMER);

        self::assertInstanceOf(CustomerOrderView::class, $view);
        self::assertSame([OrderExamples::ORDER, 'R-100001', 'paid'], [$view->orderId, $view->number, $view->status]);
        self::assertSame(['Długa 1', '00-950', 'Warszawa', 'PL'], [$view->shippingAddress->street, $view->shippingAddress->postalCode, $view->shippingAddress->city, $view->shippingAddress->country]);
        self::assertSame([['Buty Asfalt', '42', 2, 12_300, 24_600], ['Czapka', '', 1, 2_000, 2_000]], array_map(static fn ($line): array => [$line->productName, $line->variantLabel, $line->quantity, $line->unitGross, $line->lineGross], $view->lines));
        self::assertSame(OrderExamples::TOTAL, $view->totals->gross);
        self::assertEquals(new \DateTimeImmutable(OrderExamples::PLACED_AT), $view->placedAt);
        self::assertEquals(new \DateTimeImmutable(OrderExamples::DEADLINE), $view->paymentDeadline);
        self::assertEquals(new \DateTimeImmutable(self::PAID_AT), $view->paidAt);
        self::assertNull($view->shippedAt);
        self::assertNull($view->deliveredAt);
        self::assertNull($view->cancelledAt);
        self::assertNull($view->returnedAt);
        self::assertSame([], $this->orders->locked, 'a read takes no lock');
    }

    public function testACancelledOrderHasItsCancellationMomentAndNoRefundOrHistory(): void
    {
        $this->pay();
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->cancel(StaffActor::of('kc-staff-1', 'anna'), 'Brak towaru', new \DateTimeImmutable(self::CANCELLED_AT)));

        $view = $this->get(OrderExamples::ORDER, OrderExamples::CUSTOMER);

        self::assertInstanceOf(CustomerOrderView::class, $view);
        self::assertSame('cancelled', $view->status);
        self::assertEquals(new \DateTimeImmutable(self::CANCELLED_AT), $view->cancelledAt);
        $properties = array_keys(get_object_vars($view));
        foreach (['history', 'refund', 'version', 'note', 'paymentId', 'staffId', 'email', 'statusTransitions'] as $hidden) {
            self::assertNotContains($hidden, $properties);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deadlines(): iterable
    {
        yield 'before the deadline' => ['2026-11-05 12:29:59+00:00', 'false'];
        yield 'at the deadline' => [OrderExamples::DEADLINE, 'true'];
        yield 'after the deadline' => ['2026-11-05 12:31:00+00:00', 'true'];
    }

    #[DataProvider('deadlines')]
    public function testTheDeadlinePassedOfANewOrderComesFromTheClock(string $now, string $passed): void
    {
        $this->clock->now = new \DateTimeImmutable($now);

        $view = $this->get(OrderExamples::ORDER, OrderExamples::CUSTOMER);

        self::assertInstanceOf(CustomerOrderView::class, $view);
        self::assertSame('true' === $passed, $view->paymentDeadlinePassed);
    }

    public function testThePaymentDeadlineOfAPaidOrderHasNotPassedWhateverTheClock(): void
    {
        $this->pay();
        $this->clock->now = new \DateTimeImmutable('2026-11-09 12:00:00+00:00');

        $view = $this->get(OrderExamples::ORDER, OrderExamples::CUSTOMER);

        self::assertInstanceOf(CustomerOrderView::class, $view);
        self::assertFalse($view->paymentDeadlinePassed);
    }

    public function testAnOrderOfAnotherCustomerIsUnknownToThisOne(): void
    {
        self::assertNull($this->get(OrderExamples::ORDER, 'kc-sub-2'));
    }

    public function testAGuestOrderIsUnknownToEveryCustomer(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());

        self::assertNull($this->get(OrderExamples::ORDER, OrderExamples::CUSTOMER));
        self::assertNull($this->get(OrderExamples::ORDER, ''));
    }

    public function testAnUnknownOrderAndAnIdThatIsNoUuidAreUnknown(): void
    {
        self::assertNull($this->get('0192f0c4-7c1a-7c4e-9a6e-0000000000ff', OrderExamples::CUSTOMER));
        self::assertNull($this->get('not-a-uuid', OrderExamples::CUSTOMER));
        self::assertNull($this->get('', OrderExamples::CUSTOMER));
    }

    private function pay(): void
    {
        $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->markPaid(PaymentId::fromString(self::PAYMENT), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable(self::PAID_AT), new \DateTimeImmutable(self::PAID_AT)));
    }

    private function get(string $orderId, string $customerId): ?CustomerOrderView
    {
        return new GetCustomerOrderHandler($this->orders, $this->clock)(new GetCustomerOrder($orderId, $customerId));
    }
}

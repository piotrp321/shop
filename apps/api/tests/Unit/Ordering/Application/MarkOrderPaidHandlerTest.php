<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidHandler;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use App\Ordering\Application\Command\OrderChangedConcurrently;
use App\Ordering\Application\Command\OrderToPayNotFound;
use App\Ordering\Domain\InvalidOrder;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\OrderStatus;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryCustomerSummaries;
use App\Tests\Unit\Ordering\InMemorySalesHours;
use App\Tests\Unit\Ordering\InMemoryUnpaidOrderClaims;
use App\Tests\Unit\Ordering\Journal;
use App\Tests\Unit\Ordering\JournalingEventPublisher;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MarkOrderPaid, the command Payment dispatches nested in the transaction of a webhook: every result of the domain
 * becomes a result of the application, and a version conflict an exception of the application, so Payment sees
 * nothing of the Ordering domain.
 */
final class MarkOrderPaidHandlerTest extends TestCase
{
    private const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string OTHER_PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b2';

    private VersionedOrders $orders;
    private JournalingEventPublisher $events;
    private FixedOrderingClock $clock;
    private InMemorySalesHours $sales;
    private InMemoryCustomerSummaries $customers;
    private InMemoryUnpaidOrderClaims $claims;

    protected function setUp(): void
    {
        $this->sales = new InMemorySalesHours();
        $this->customers = new InMemoryCustomerSummaries();
        $this->claims = new InMemoryUnpaidOrderClaims();
        $this->claims->claims = [OrderExamples::ORDER => ['address' => '203.0.113.7', 'identity' => 'email:jan@example.com', 'expiresAt' => new \DateTimeImmutable('2026-11-05 12:35:00+00:00')]];
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed());
        $this->events = new JournalingEventPublisher(new Journal());
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:31:00+00:00'));
    }

    public function testAPaymentExecutedByTheDeadlinePaysTheOrder(): void
    {
        $result = $this->handle(self::command(executedAt: OrderExamples::DEADLINE));

        self::assertSame(MarkOrderPaidResult::Paid, $result);
        $order = $this->order();
        self::assertSame(OrderStatus::Paid, $order->status());
        self::assertSame(self::PAYMENT, $order->paymentId()?->toString());
        self::assertEquals($this->clock->now, $order->paidAt());
        self::assertEquals([new OrderPaid(OrderExamples::ORDER, $this->clock->now)], $this->events->published);
        self::assertSame([OrderExamples::ORDER], $this->orders->saved);
        self::assertEquals([['orderId' => OrderExamples::ORDER, 'paidAt' => $this->clock->now, 'gross' => OrderExamples::TOTAL]], $this->sales->added, 'the paid order is added to the sales of its hour once');
        self::assertSame([], $this->claims->claims, 'a paid order is no unpaid order any more (ABUSE-LIMITS, D7)');
    }

    public function testAPaymentThatPaysAnOrderOfACustomerIsAddedToTheCustomerOnce(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed(byCustomer: true));

        self::assertSame(MarkOrderPaidResult::Paid, $this->handle(self::command()));
        self::assertSame(MarkOrderPaidResult::AlreadyPaid, $this->handle(self::command()));

        self::assertSame([['customerId' => OrderExamples::CUSTOMER, 'orderId' => OrderExamples::ORDER, 'gross' => OrderExamples::TOTAL]], $this->customers->payments);
    }

    public function testThePaymentOfAnOrderWithADeliveryAddsTheGoodsToTheSalesAndTheWholeTotalToTheCustomer(): void
    {
        $this->orders = new VersionedOrders();
        $this->orders->add(OrderExamples::placed(byCustomer: true, delivery: OrderExamples::paidDelivery()));

        self::assertSame(MarkOrderPaidResult::Paid, $this->handle(self::command(amount: OrderExamples::TOTAL + OrderExamples::DELIVERY_COST)));

        self::assertEquals([['orderId' => OrderExamples::ORDER, 'paidAt' => $this->clock->now, 'gross' => OrderExamples::TOTAL]], $this->sales->added, 'the sales count the goods without the delivery (P14)');
        self::assertSame([['customerId' => OrderExamples::CUSTOMER, 'orderId' => OrderExamples::ORDER, 'gross' => OrderExamples::TOTAL + OrderExamples::DELIVERY_COST]], $this->customers->payments, 'the customer the whole total');
    }

    public function testAPaymentOfAnOrderOfAGuestAddsNoCustomer(): void
    {
        self::assertSame(MarkOrderPaidResult::Paid, $this->handle(self::command()));

        self::assertSame([], $this->customers->payments);
    }

    /**
     * @return iterable<string, array{\Closure(self): void, MarkOrderPaid, MarkOrderPaidResult}>
     */
    public static function resultsWithoutAChange(): iterable
    {
        $paid = static function (self $test): void {
            $test->handle(self::command());
            $test->events->published = [];
            $test->orders->saved = [];
            $test->sales->added = [];
            $test->customers->payments = [];
            $test->claims->claims = ['other' => ['address' => '203.0.113.7', 'identity' => 'email:jan@example.com', 'expiresAt' => new \DateTimeImmutable('2026-11-05 12:35:00+00:00')]];
        };

        yield 'the same payment again' => [$paid, self::command(), MarkOrderPaidResult::AlreadyPaid];
        yield 'another payment' => [$paid, self::command(paymentId: self::OTHER_PAYMENT), MarkOrderPaidResult::PaidByAnotherPayment];
        yield 'executed a second after the deadline' => [static function (): void {}, self::command(executedAt: '2026-11-05 12:30:01+00:00'), MarkOrderPaidResult::PaidAfterDeadline];
        yield 'a cancelled order' => [static function (self $test): void {
            $test->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (Order $order) => $order->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00')));
            $test->orders->saved = [];
        }, self::command(), MarkOrderPaidResult::OrderCancelled];
    }

    /**
     * @param \Closure(self): void $before
     */
    #[DataProvider('resultsWithoutAChange')]
    public function testEveryOtherResultIsTranslatedAndChangesNothing(\Closure $before, MarkOrderPaid $command, MarkOrderPaidResult $expected): void
    {
        $before($this);

        self::assertSame($expected, $this->handle($command));
        self::assertSame([], $this->orders->saved);
        self::assertSame([], $this->events->published);
        self::assertSame([], $this->sales->added, 'only a payment that pays the order adds to the sales');
        self::assertSame([], $this->customers->payments, 'nor to the customer');
        self::assertCount(1, $this->claims->claims, 'nor removes a claim: a payment after the deadline leaves the order new');
    }

    public function testAnUnknownOrderOrAnIdThatIsNoUuidIsOrderToPayNotFound(): void
    {
        foreach (['0192f0c4-7c1a-7c4e-9a6e-0000000000ff', 'not-a-uuid'] as $orderId) {
            try {
                $this->handle(self::command(orderId: $orderId));
                self::fail('Expected OrderToPayNotFound for '.$orderId);
            } catch (OrderToPayNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAVersionConflictIsOrderChangedConcurrentlyWithoutAnEvent(): void
    {
        // Another handler saved the order between the read and the save of this handler.
        $this->orders->beforeSave = fn () => $this->orders->changeElsewhere(OrderId::fromString(OrderExamples::ORDER), static fn (): null => null);

        try {
            $this->handle(self::command());
            self::fail('Expected OrderChangedConcurrently.');
        } catch (OrderChangedConcurrently $conflict) {
            self::assertNotNull($conflict->getPrevious());
        }
        self::assertSame(OrderStatus::New, $this->order()->status());
        self::assertSame([], $this->events->published);
        self::assertSame([], $this->sales->added, 'a payment that lost the race adds nothing');

        // Retried (Payment's webhook is delivered again), it pays the order and adds it.
        self::assertSame(MarkOrderPaidResult::Paid, $this->handle(self::command()));
        self::assertSame([OrderExamples::ORDER], $this->addedOrders());
    }

    public function testAnAmountOtherThanTheTotalIsALogicErrorThatChangesNothing(): void
    {
        $this->expectException(InvalidOrder::class);

        try {
            $this->handle(self::command(amount: OrderExamples::TOTAL - 1));
        } finally {
            self::assertSame([], $this->orders->saved);
            self::assertSame([], $this->events->published);
            self::assertSame([], $this->sales->added);
            self::assertCount(1, $this->claims->claims);
        }
    }

    private function handle(MarkOrderPaid $command): MarkOrderPaidResult
    {
        return new MarkOrderPaidHandler($this->orders, $this->events, $this->clock, $this->sales, $this->customers, $this->claims)($command);
    }

    private static function command(
        string $orderId = OrderExamples::ORDER,
        string $paymentId = self::PAYMENT,
        int $amount = OrderExamples::TOTAL,
        string $executedAt = '2026-11-05 12:20:00+00:00',
    ): MarkOrderPaid {
        return new MarkOrderPaid($orderId, $paymentId, $amount, 'PLN', new \DateTimeImmutable($executedAt));
    }

    /**
     * @return list<string>
     */
    private function addedOrders(): array
    {
        return array_column($this->sales->added, 'orderId');
    }

    private function order(): Order
    {
        return $this->orders->get(OrderId::fromString(OrderExamples::ORDER));
    }
}

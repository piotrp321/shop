<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\Query\GetOrderPayability;
use App\Ordering\Application\Query\GetOrderPayabilityHandler;
use App\Ordering\Application\Query\OrderPayability;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\StaffActor;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\OrderExamples;
use App\Tests\Unit\Ordering\VersionedOrders;
use PHPUnit\Framework\TestCase;

/**
 * GetOrderPayability, what payment:close-abandoned learns of the orders of a batch of pending payments (spec:
 * docs/features/async-reliability/spec.md, W6): only whether each one is payable now and its status for Payment, no
 * number, total or personal data, so no access check; up to 100 orders in one query.
 */
final class GetOrderPayabilityHandlerTest extends TestCase
{
    private const string NEW_PAST_DEADLINE = '0192f0c4-7c1a-7c4e-9a6e-0000000000b2';
    private const string PAID = '0192f0c4-7c1a-7c4e-9a6e-0000000000b3';
    private const string SHIPPED = '0192f0c4-7c1a-7c4e-9a6e-0000000000b4';
    private const string CANCELLED = '0192f0c4-7c1a-7c4e-9a6e-0000000000b5';

    private VersionedOrders $orders;
    private FixedOrderingClock $clock;

    protected function setUp(): void
    {
        $this->orders = new VersionedOrders();
        // OrderExamples::ORDER: placed 12:00, deadline 12:30.
        $this->orders->add(OrderExamples::placed());
        $this->orders->add(self::placedAs(self::NEW_PAST_DEADLINE, '-1 hour'));
        $paid = self::placedAs(self::PAID);
        $paid->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000c1'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:05:00+00:00'), new \DateTimeImmutable('2026-11-05 12:05:01+00:00'));
        $this->orders->add($paid);
        $shipped = self::placedAs(self::SHIPPED);
        $shipped->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000c2'), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:05:00+00:00'), new \DateTimeImmutable('2026-11-05 12:05:01+00:00'));
        $shipped->ship(StaffActor::of('kc-staff-1', 'anna'), null, null, new \DateTimeImmutable('2026-11-05 12:06:00+00:00'));
        $this->orders->add($shipped);
        $cancelled = self::placedAs(self::CANCELLED, '-1 hour');
        $cancelled->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:00:00+00:00'));
        $this->orders->add($cancelled);
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'));
    }

    public function testEachOrderIsPayableOrNotWithItsStatusForPayment(): void
    {
        $result = $this->ask([OrderExamples::ORDER, self::NEW_PAST_DEADLINE, self::PAID, self::SHIPPED, self::CANCELLED]);

        self::assertEquals([
            new OrderPayability(OrderExamples::ORDER, true, 'new'),
            new OrderPayability(self::NEW_PAST_DEADLINE, false, 'new'),
            new OrderPayability(self::PAID, false, 'paid'),
            new OrderPayability(self::SHIPPED, false, 'paid'),
            new OrderPayability(self::CANCELLED, false, 'cancelled'),
        ], $result);
    }

    public function testAnOrderIsNoLongerPayableAtItsDeadline(): void
    {
        $this->clock->now = new \DateTimeImmutable(OrderExamples::DEADLINE);

        self::assertEquals([new OrderPayability(OrderExamples::ORDER, false, 'new')], $this->ask([OrderExamples::ORDER]));
    }

    public function testUnknownOrdersAndIdsThatAreNoUuidAreLeftOut(): void
    {
        self::assertEquals(
            [new OrderPayability(OrderExamples::ORDER, true, 'new')],
            $this->ask(['0192f0c4-7c1a-7c4e-9a6e-0000000000ff', 'not-a-uuid', OrderExamples::ORDER]),
        );
        self::assertSame([], $this->ask([]));
    }

    public function testTheAnswerHoldsNoDataOfTheOrderBeyondItsPayabilityAndStatus(): void
    {
        $result = $this->ask([OrderExamples::ORDER]);

        self::assertSame(['orderId', 'payable', 'statusForPayment'], array_keys(get_object_vars($result[0])));
    }

    /**
     * An order that exists but cannot be read (its stored data breaks an invariant of the domain made stricter later)
     * is not an unknown order: it is reported apart with the reason, so its payment is not closed as abandoned.
     */
    public function testAnOrderThatCannotBeReadIsReportedApartFromTheUnknownOnes(): void
    {
        $orders = $this->failingOn(self::PAID, new \InvalidArgumentException('A tax class has 1 to 20 characters.'));

        $answer = new GetOrderPayabilityHandler($orders, $this->clock)(new GetOrderPayability([OrderExamples::ORDER, self::PAID, '0192f0c4-7c1a-7c4e-9a6e-0000000000ff']));

        self::assertEquals([new OrderPayability(OrderExamples::ORDER, true, 'new')], $answer->orders);
        self::assertSame([self::PAID => 'InvalidArgumentException: A tax class has 1 to 20 characters.'], $answer->unreadable);
    }

    public function testAFailureOfTheDatabaseIsThrown(): void
    {
        $failure = new \RuntimeException('connection refused');
        $orders = $this->failingOn(self::PAID, $failure);

        try {
            new GetOrderPayabilityHandler($orders, $this->clock)(new GetOrderPayability([OrderExamples::ORDER, self::PAID]));
            self::fail('Expected the failure of the database.');
        } catch (\RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }
    }

    public function testMoreThan100OrdersAreRefused(): void
    {
        $ids = array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 101));

        $this->expectException(\InvalidArgumentException::class);

        $this->ask($ids);
    }

    public function testUpTo100OrdersAreOneQuery(): void
    {
        $ids = array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 99));
        $ids[] = OrderExamples::ORDER;

        self::assertEquals([new OrderPayability(OrderExamples::ORDER, true, 'new')], $this->ask($ids));
    }

    /**
     * @param list<string> $orderIds
     *
     * @return list<OrderPayability>
     */
    private function ask(array $orderIds): array
    {
        $answer = new GetOrderPayabilityHandler($this->orders, $this->clock)(new GetOrderPayability($orderIds));
        self::assertSame([], $answer->unreadable);

        return $answer->orders;
    }

    /**
     * The orders of the test, of which reading the order $orderId throws $failure.
     */
    private function failingOn(string $orderId, \Throwable $failure): OrderRepository
    {
        return new readonly class($this->orders, $orderId, $failure) implements OrderRepository {
            public function __construct(private VersionedOrders $orders, private string $failing, private \Throwable $failure)
            {
            }

            public function add(Order $order): void
            {
                $this->orders->add($order);
            }

            public function save(Order $order): void
            {
                $this->orders->save($order);
            }

            public function get(OrderId $id): Order
            {
                return $id->toString() === $this->failing ? throw $this->failure : $this->orders->get($id);
            }

            public function lock(OrderId $id): Order
            {
                return $this->get($id);
            }

            public function findByIdempotencyKey(IdempotencyKey $key): ?Order
            {
                return null;
            }
        };
    }

    /**
     * The order of OrderExamples::placed() under another id, placed at another moment.
     */
    private static function placedAs(string $id, string $shift = '+0 minutes'): Order
    {
        $order = OrderExamples::placed();

        return Order::reconstitute(
            id: OrderId::fromString($id),
            number: $order->number(),
            owner: $order->owner(),
            accessToken: $order->accessToken(),
            contact: $order->contact(),
            shippingAddress: $order->shippingAddress(),
            lines: $order->lines(),
            discount: null,
            delivery: $order->delivery(),
            status: $order->status(),
            idempotencyKey: $order->idempotencyKey(),
            requestFingerprint: $order->requestFingerprint(),
            placedAt: $order->placedAt()->modify($shift),
            paymentDeadline: $order->paymentDeadline()->modify($shift),
            paidAt: null,
            paymentId: null,
            cancelledAt: null,
            cancellationReason: null,
            shippedAt: null,
            deliveredAt: null,
            returnedAt: null,
            refund: null,
            history: $order->history(),
            version: 1,
        );
    }
}

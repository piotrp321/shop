<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\Currency;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderDelivery;
use App\Ordering\Domain\OrderShipped;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\StaffActor;
use App\Ordering\Domain\TrackingNumber;
use App\Ordering\Domain\TrackingNumberNotChangeable;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tracking number of an order (docs/features/delivery/spec.md, W8, W8a, P18): set when staff ship it, added,
 * replaced or removed afterwards while it is shipped or delivered, and never recorded as an event or a history entry.
 */
final class OrderTrackingNumberTest extends TestCase
{
    private const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string SHIPPED_AT = '2026-11-06 09:00:00+00:00';

    public function testAShipmentKeepsTheNumber(): void
    {
        $order = self::paid();

        $order->ship(self::staff(), 'Kurier', TrackingNumber::fromString('JJD-123'), new \DateTimeImmutable(self::SHIPPED_AT));

        self::assertSame('JJD-123', $order->trackingNumber()?->toString());
        self::assertSame(OrderStatus::Shipped, $order->status());
        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderShipped::class, $events[0]);
    }

    public function testAShipmentWithoutANumberHasNone(): void
    {
        $order = self::paid();

        $order->ship(self::staff(), null, null, new \DateTimeImmutable(self::SHIPPED_AT));

        self::assertNull($order->trackingNumber());
        self::assertSame(OrderStatus::Shipped, $order->status());
    }

    public function testANewOrderHasNoNumber(): void
    {
        self::assertNull(OrderExamples::placed()->trackingNumber());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changeableStates(): iterable
    {
        yield 'shipped' => ['shipped'];
        yield 'delivered' => ['delivered'];
    }

    #[DataProvider('changeableStates')]
    public function testTheNumberIsAddedReplacedAndRemovedAfterTheShipment(string $state): void
    {
        $order = self::in($state);
        $history = $order->history();
        self::assertNull($order->trackingNumber());

        $order->changeTrackingNumber(TrackingNumber::fromString('AAA-1'));
        self::assertSame('AAA-1', $order->trackingNumber()?->toString());
        $order->changeTrackingNumber(TrackingNumber::fromString('BBB-2'));
        self::assertSame('BBB-2', $order->trackingNumber()?->toString());
        $order->changeTrackingNumber(null);
        self::assertNull($order->trackingNumber());

        self::assertSame($history, $order->history(), 'no history entry');
        self::assertSame([], $order->releaseEvents(), 'no event');
        self::assertSame(OrderStatus::from($state), $order->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unchangeableStates(): iterable
    {
        yield 'new' => ['new'];
        yield 'paid' => ['paid'];
        yield 'cancelled by staff' => ['cancelled by staff'];
        yield 'expired' => ['expired'];
        yield 'returned' => ['returned'];
    }

    #[DataProvider('unchangeableStates')]
    public function testTheNumberIsChangedOnlyWhileShippedOrDelivered(string $state): void
    {
        $order = self::in($state);
        $before = [$order->status(), $order->trackingNumber(), $order->history()];

        try {
            $order->changeTrackingNumber(TrackingNumber::fromString('AAA-1'));
            self::fail('Expected TrackingNumberNotChangeable.');
        } catch (TrackingNumberNotChangeable $refusal) {
            self::assertSame('Numer przesyłki można zmienić tylko w zamówieniu wysłanym albo dostarczonym.', $refusal->getMessage());
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/tracking-number-not-changeable', $refusal->problemType());
        }
        self::assertSame($before, [$order->status(), $order->trackingNumber(), $order->history()]);
    }

    public function testRemovingTheNumberOfAnOrderThatIsNotShippedIsRefusedToo(): void
    {
        $this->expectException(TrackingNumberNotChangeable::class);

        self::in('paid')->changeTrackingNumber(null);
    }

    public function testTheNumberIsRestoredFromTheStoredOrder(): void
    {
        $order = self::in('shipped');
        $order->changeTrackingNumber(TrackingNumber::fromString('AAA-1'));

        self::assertSame('AAA-1', OrderExamples::copy($order)->trackingNumber()?->toString());
    }

    public function testTheLinkIsTheTemplateWithTheEncodedNumber(): void
    {
        $delivery = OrderExamples::freeDelivery();

        self::assertSame('https://tracking.example.com/JJD-123', $delivery->trackingUrlFor(TrackingNumber::fromString('JJD-123')));
    }

    public function testTheLinkIsNullWithoutATemplate(): void
    {
        $delivery = OrderDelivery::of(OrderExamples::DELIVERY_METHOD, 'Kurier ekspresowy', null, 0, [], Currency::PLN);

        self::assertNull($delivery->trackingUrlFor(TrackingNumber::fromString('JJD-123')));
    }

    private static function staff(): StaffActor
    {
        return StaffActor::of('kc-staff-1', 'anna');
    }

    private static function paid(): Order
    {
        $order = OrderExamples::placed();
        $order->markPaid(PaymentId::fromString(self::PAYMENT), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'));
        $order->releaseEvents();

        return $order;
    }

    private static function in(string $state): Order
    {
        if ('new' === $state) {
            return OrderExamples::placed();
        }
        if ('expired' === $state) {
            $order = OrderExamples::placed();
            $order->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00'));
            $order->releaseEvents();

            return $order;
        }
        $order = self::paid();
        if ('cancelled by staff' === $state) {
            $order->cancel(self::staff(), 'Klient zrezygnował', new \DateTimeImmutable(self::SHIPPED_AT));
        }
        if (\in_array($state, ['shipped', 'delivered', 'returned'], true)) {
            $order->ship(self::staff(), null, null, new \DateTimeImmutable(self::SHIPPED_AT));
        }
        if ('delivered' === $state) {
            $order->deliver(self::staff(), null, new \DateTimeImmutable('2026-11-07 10:00:00+00:00'));
        }
        if ('returned' === $state) {
            $order->registerReturn(self::staff(), 'Zwrot towaru', new \DateTimeImmutable('2026-11-08 10:00:00+00:00'));
        }
        $order->releaseEvents();

        return $order;
    }
}

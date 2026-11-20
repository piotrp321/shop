<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Application\View\CustomerOrderView;
use App\Ordering\Application\View\OrderDeliveryView;
use App\Ordering\Application\View\OrderShipmentView;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderDelivery;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\StaffActor;
use App\Ordering\Domain\TrackingNumber;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\TestCase;

/**
 * The shipment in the views of an order (docs/features/delivery/spec.md, W8): only with a tracking number; the carrier
 * is the name of the method, the link comes from the copy of its template with the number encoded; the delivery stays
 * with its method name with or without the number.
 */
final class OrderShipmentViewsTest extends TestCase
{
    private const string NOW = '2026-11-06 09:00:00+00:00';

    public function testAnOrderWithoutANumberHasNoShipmentButKeepsItsDelivery(): void
    {
        $order = self::shipped(null);

        foreach (self::views($order) as $view) {
            self::assertNull($view->shipment);
            self::assertEquals(new OrderDeliveryView('Kurier standardowy', OrderExamples::DELIVERY_COST), $view->delivery);
        }
    }

    public function testEveryViewHasTheCarrierTheNumberAndTheLink(): void
    {
        $order = self::shipped('JJD-0002-44');

        foreach (self::views($order) as $view) {
            self::assertEquals(new OrderShipmentView('Kurier standardowy', 'JJD-0002-44', 'https://tracking.example.com/JJD-0002-44'), $view->shipment);
        }
    }

    public function testTheLinkIsNullWithoutATemplate(): void
    {
        $order = self::shipped('JJD-1', OrderDelivery::of(OrderExamples::DELIVERY_METHOD, 'Kurier ekspresowy', null, 0, [], Currency::PLN));

        foreach (self::views($order) as $view) {
            self::assertEquals(new OrderShipmentView('Kurier ekspresowy', 'JJD-1', null), $view->shipment);
        }
    }

    public function testAnOrderWithoutAMethodHasTheNumberWithoutCarrierAndLink(): void
    {
        $order = OrderExamples::copy(self::shipped('JJD-1'), withoutDelivery: true);

        foreach (self::views($order) as $view) {
            self::assertNull($view->delivery);
            self::assertEquals(new OrderShipmentView(null, 'JJD-1', null), $view->shipment);
        }
    }

    public function testANumberRemovedAfterwardsLeavesNoShipment(): void
    {
        $order = self::shipped('JJD-1');
        $order->changeTrackingNumber(null);

        foreach (self::views($order) as $view) {
            self::assertNull($view->shipment);
        }
    }

    /**
     * @return list<OrderView|CustomerOrderView|BackofficeOrderView>
     */
    private static function views(Order $order): array
    {
        $now = new \DateTimeImmutable(self::NOW);

        return [OrderView::of($order), CustomerOrderView::of($order, $now), BackofficeOrderView::of($order, $now)];
    }

    private static function shipped(?string $number, ?OrderDelivery $delivery = null): Order
    {
        $order = OrderExamples::placed(byCustomer: true, delivery: $delivery ?? OrderExamples::paidDelivery());
        $order->markPaid(PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000b1'), OrderExamples::TOTAL + ($delivery?->gross()->amount() ?? OrderExamples::DELIVERY_COST), 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'));
        $order->ship(StaffActor::of('kc-staff-1', 'anna'), null, null === $number ? null : TrackingNumber::fromString($number), new \DateTimeImmutable(self::NOW));
        $order->releaseEvents();

        return $order;
    }
}

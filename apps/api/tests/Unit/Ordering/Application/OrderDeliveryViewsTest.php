<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Application\View\CustomerOrderView;
use App\Ordering\Application\View\OrderDeliveryView;
use App\Ordering\Application\View\OrderView;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\TestCase;

/**
 * The delivery in the views of an order (docs/features/delivery/spec.md, W6): the name of the method and its cost,
 * null for an order without a method; the totals include the delivery.
 */
final class OrderDeliveryViewsTest extends TestCase
{
    public function testEveryViewHasTheMethodAndTheCostAndTheTotalsWithIt(): void
    {
        $order = OrderExamples::placed(byCustomer: true, delivery: OrderExamples::paidDelivery());
        $now = new \DateTimeImmutable('2026-11-05 12:10:00+00:00');
        $expected = new OrderDeliveryView('Kurier standardowy', OrderExamples::DELIVERY_COST);

        foreach ([OrderView::of($order), CustomerOrderView::of($order, $now), BackofficeOrderView::of($order, $now)] as $view) {
            self::assertEquals($expected, $view->delivery);
            self::assertSame(OrderExamples::TOTAL + OrderExamples::DELIVERY_COST, $view->totals->gross);
        }
    }

    public function testAFreeDeliveryCostsNothing(): void
    {
        self::assertEquals(new OrderDeliveryView('Kurier standardowy', 0), OrderView::of(OrderExamples::placed())->delivery);
    }

    public function testAnOrderWithoutAMethodHasNoDelivery(): void
    {
        $order = OrderExamples::copy(OrderExamples::placed(byCustomer: true), withoutDelivery: true);
        $now = new \DateTimeImmutable('2026-11-05 12:10:00+00:00');

        foreach ([OrderView::of($order), CustomerOrderView::of($order, $now), BackofficeOrderView::of($order, $now)] as $view) {
            self::assertNull($view->delivery);
            self::assertSame(OrderExamples::TOTAL, $view->totals->gross);
        }
    }
}

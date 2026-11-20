<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\OrderShippedText;
use App\Ordering\Application\View\OrderDeliveryView;
use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\OrderShipmentView;
use App\Ordering\Application\View\OrderTotalsView;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Application\View\ShippingAddressView;
use PHPUnit\Framework\TestCase;

/**
 * The email about a shipment (docs/features/delivery/spec.md, W8): "Sposób dostawy" whenever the order has a method,
 * the number and the link only with a number (the link only with a template), never a "Przewoźnik" line.
 */
final class OrderShippedTextTest extends TestCase
{
    public function testTheSubject(): void
    {
        self::assertSame('Zamówienie R-100001 wysłane', new OrderShippedText()->subject(self::view(null, null)));
    }

    public function testAnOrderWithoutAMethodHasTheAddressOnly(): void
    {
        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            Numer zamówienia: R-100001

            Zamówienie zostało wysłane na adres:
            Jan Kowalski
            Długa 1
            00-950 Warszawa

            TEXT;
        self::assertSame($expected, new OrderShippedText()->body(self::view(null, null)));
    }

    public function testTheMethodIsShownWithoutANumber(): void
    {
        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            Numer zamówienia: R-100001

            Zamówienie zostało wysłane na adres:
            Jan Kowalski
            Długa 1
            00-950 Warszawa

            Sposób dostawy: Kurier standardowy

            TEXT;
        self::assertSame($expected, new OrderShippedText()->body(self::view(new OrderDeliveryView('Kurier standardowy', 1_999), null)));
    }

    public function testTheNumberAndTheLinkFollowTheMethod(): void
    {
        $view = self::view(new OrderDeliveryView('Kurier standardowy', 1_999), new OrderShipmentView('Kurier standardowy', 'JJD-0002-44', 'https://tracking.example.com/JJD-0002-44'));

        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            Numer zamówienia: R-100001

            Zamówienie zostało wysłane na adres:
            Jan Kowalski
            Długa 1
            00-950 Warszawa

            Sposób dostawy: Kurier standardowy
            Numer przesyłki: JJD-0002-44
            Śledzenie przesyłki: https://tracking.example.com/JJD-0002-44

            TEXT;
        $body = new OrderShippedText()->body($view);
        self::assertSame($expected, $body);
        self::assertStringNotContainsString('Przewoźnik', $body);
    }

    public function testThereIsNoLinkWithoutATemplate(): void
    {
        $view = self::view(new OrderDeliveryView('Kurier ekspresowy', 3_999), new OrderShipmentView('Kurier ekspresowy', 'JJD-1', null));

        $body = new OrderShippedText()->body($view);

        self::assertStringContainsString("Sposób dostawy: Kurier ekspresowy\nNumer przesyłki: JJD-1\n", $body);
        self::assertStringNotContainsString('Śledzenie przesyłki', $body);
    }

    public function testANumberOfAnOrderWithoutAMethodHasNoMethodLine(): void
    {
        $body = new OrderShippedText()->body(self::view(null, new OrderShipmentView(null, 'JJD-1', null)));

        self::assertStringNotContainsString('Sposób dostawy', $body);
        self::assertStringContainsString("Numer przesyłki: JJD-1\n", $body);
    }

    private static function view(?OrderDeliveryView $delivery, ?OrderShipmentView $shipment): OrderView
    {
        return new OrderView(
            '0192f0c4-7c1a-7c4e-9a6e-0000000000aa',
            'R-100001',
            'shipped',
            'jan@example.com',
            'Jan Kowalski',
            '+48600100200',
            new ShippingAddressView('Długa 1', '00-950', 'Warszawa', 'PL'),
            [new OrderLineView('0192f0c4-7c1a-7c4e-9a6e-000000000001', 'Buty Asfalt', '42', 1, 125_456, 125_456, 0, 125_456, null)],
            null,
            $delivery,
            $shipment,
            new OrderTotalsView(102_000, 23_456, 125_456, 'PLN'),
            new \DateTimeImmutable('2026-11-05 12:00:00+00:00'),
            new \DateTimeImmutable('2026-11-05 12:30:00+00:00'),
            null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\OrderCancelledByStaffText;
use App\Ordering\Application\OrderCancelledText;
use App\Ordering\Application\OrderShippedText;
use App\Ordering\Application\PaymentReceivedText;
use App\Ordering\Application\ReturnReceivedText;
use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\OrderTotalsView;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Application\View\ShippingAddressView;
use PHPUnit\Framework\TestCase;

/**
 * The emails about the payment, the shipment, the cancellation and the return of an order, in Polish, amounts like in
 * the confirmation. The note of a staff member is never part of them.
 */
final class OrderStatusTextsTest extends TestCase
{
    public function testThePaymentReceivedEmail(): void
    {
        $text = new PaymentReceivedText();

        self::assertSame('Płatność przyjęta – zamówienie R-100001', $text->subject(self::view()));
        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            otrzymaliśmy płatność za zamówienie.

            Numer zamówienia: R-100001
            Kwota: 1 254,56 zł

            Zamówienie przekazujemy do realizacji.

            TEXT;
        self::assertSame($expected, $text->body(self::view()));
    }

    public function testTheOrderCancelledEmail(): void
    {
        $text = new OrderCancelledText();

        self::assertSame('Zamówienie R-100001 anulowane', $text->subject(self::view()));
        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            Numer zamówienia: R-100001

            Nie otrzymaliśmy płatności w terminie, więc anulowaliśmy zamówienie.

            TEXT;
        self::assertSame($expected, $text->body(self::view()));
    }

    public function testTheOrderShippedEmailHasTheAddress(): void
    {
        $text = new OrderShippedText();

        self::assertSame('Zamówienie R-100001 wysłane', $text->subject(self::view()));
        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            Numer zamówienia: R-100001

            Zamówienie zostało wysłane na adres:
            Jan Kowalski
            Długa 1
            00-950 Warszawa

            TEXT;
        self::assertSame($expected, $text->body(self::view()));
    }

    public function testTheEmailOfACancellationByStaffPromisesTheRefundWithoutTheTextAboutThePayment(): void
    {
        $text = new OrderCancelledByStaffText();

        self::assertSame('Zamówienie R-100001 anulowane', $text->subject(self::view()));
        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            Numer zamówienia: R-100001

            Anulowaliśmy Twoje zamówienie. Zwrócimy płatność.

            TEXT;
        self::assertSame($expected, $text->body(self::view()));
        self::assertStringNotContainsString('Nie otrzymaliśmy płatności', $text->body(self::view()));
    }

    public function testTheReturnReceivedEmailDoesNotSayTheCustomerSentTheGoodsBack(): void
    {
        $text = new ReturnReceivedText();

        self::assertSame('Zamówienie R-100001 wróciło do nas', $text->subject(self::view()));
        $expected = <<<'TEXT'
            Dzień dobry Jan Kowalski,

            Zamówienie R-100001 wróciło do nas. Zwrócimy płatność.

            TEXT;
        self::assertSame($expected, $text->body(self::view()));
    }

    public function testNoEmailShowsTheAccessToken(): void
    {
        $view = self::view();

        foreach ([new PaymentReceivedText()->body($view), new OrderCancelledText()->body($view), new OrderShippedText()->body($view), new OrderCancelledByStaffText()->body($view), new ReturnReceivedText()->body($view)] as $body) {
            self::assertIsString($view->accessToken);
            self::assertStringNotContainsString($view->accessToken, $body);
        }
    }

    private static function view(): OrderView
    {
        return new OrderView(
            '0192f0c4-7c1a-7c4e-9a6e-0000000000aa',
            'R-100001',
            'paid',
            'jan@example.com',
            'Jan Kowalski',
            '+48600100200',
            new ShippingAddressView('Długa 1', '00-950', 'Warszawa', 'PL'),
            [new OrderLineView('0192f0c4-7c1a-7c4e-9a6e-000000000001', 'Buty Asfalt', '42', 1, 125_456, 125_456, 0, 125_456, null)],
            null,
            new OrderTotalsView(102_000, 23_456, 125_456, 'PLN'),
            new \DateTimeImmutable('2026-11-05 12:00:00+00:00'),
            new \DateTimeImmutable('2026-11-05 12:30:00+00:00'),
            'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM',
        );
    }
}

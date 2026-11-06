<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\OrderCancelledText;
use App\Ordering\Application\PaymentReceivedText;
use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\OrderTotalsView;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Application\View\ShippingAddressView;
use PHPUnit\Framework\TestCase;

/**
 * The emails about the payment and the cancellation of an order, in Polish, amounts like in the confirmation.
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

    public function testNeitherEmailShowsTheAccessToken(): void
    {
        $view = self::view();

        foreach ([new PaymentReceivedText()->body($view), new OrderCancelledText()->body($view)] as $body) {
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
            [new OrderLineView('0192f0c4-7c1a-7c4e-9a6e-000000000001', 'Buty Asfalt', '42', 1, 125_456, 125_456)],
            new OrderTotalsView(102_000, 23_456, 125_456, 'PLN'),
            new \DateTimeImmutable('2026-11-05 12:00:00+00:00'),
            new \DateTimeImmutable('2026-11-05 12:30:00+00:00'),
            'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM',
        );
    }
}

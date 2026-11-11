<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\OrderNotFound;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\Query\GetPaymentStatus;
use App\Payment\Application\Query\GetPaymentStatusHandler;
use App\Payment\Application\Query\LatestPaymentView;
use App\Payment\Application\Query\ListPaymentProviders;
use App\Payment\Application\Query\ListPaymentProvidersHandler;
use App\Payment\Application\Query\PaymentProviderView;
use App\Payment\Application\Query\PaymentStatusView;
use App\Payment\Domain\Currency;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderState;
use App\Payment\Domain\OrderToPay;
use App\Payment\Domain\Payment;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use App\Tests\Unit\Payment\InMemoryPayableOrders;
use App\Tests\Unit\Payment\InMemoryPayments;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GetPaymentStatus: what the confirmation page shows, only with access to the order; and the list of providers.
 */
final class GetPaymentStatusHandlerTest extends TestCase
{
    private const string TOKEN = 'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM';
    private const string DEADLINE = '2026-11-05 12:30:00+00:00';

    private InMemoryPayments $payments;
    private InMemoryPayableOrders $orders;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->orders = new InMemoryPayableOrders();
        $this->orders->add(self::order(), PaymentAccess::guest(self::TOKEN));
    }

    public function testAnOrderWithoutAPaymentCanStartOne(): void
    {
        self::assertEquals(
            new PaymentStatusView('R-100001', 'new', new \DateTimeImmutable(self::DEADLINE), PaymentExamples::TOTAL, 'PLN', true, null),
            $this->paymentStatus(),
        );
    }

    public function testAPendingPaymentOfAPayableOrderCanBeResumed(): void
    {
        $this->payments->add(PaymentExamples::withSession(ProviderCode::DemoCard));

        $status = $this->paymentStatus();

        self::assertTrue($status->canStartPayment, 'a new attempt cancels the pending one at its provider first');
        self::assertEquals(new LatestPaymentView('demo-card', 'pending', PaymentExamples::REDIRECT), $status->latestPayment);
    }

    public function testAPendingPaymentPastTheDeadlineCannotBeResumed(): void
    {
        $this->orders->add(self::order(payable: false), PaymentAccess::guest(self::TOKEN));
        $this->payments->add(PaymentExamples::withSession());

        $status = $this->paymentStatus();

        self::assertFalse($status->canStartPayment);
        self::assertEquals(new LatestPaymentView('demo-transfer', 'pending', null), $status->latestPayment);
    }

    public function testTheLatestPaymentIsTheNewest(): void
    {
        $older = PaymentExamples::withSession();
        $older->fail(new \DateTimeImmutable('2026-11-05 12:06:00+00:00'));
        $this->payments->add($older);
        $newer = Payment::start(
            \App\Payment\Domain\PaymentId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000a2'),
            $older->orderId(),
            ProviderCode::DemoCard,
            PaymentExamples::amount(),
            \App\Payment\Domain\IdempotencyKey::fromString('second'),
            \App\Payment\Domain\RequestFingerprint::of($older->orderId(), ProviderCode::DemoCard),
            new \DateTimeImmutable('2026-11-05 12:07:00+00:00'),
        );
        $newer->attachProviderSession(ProviderPaymentId::fromString('cs_2'), 'http://provider.test/pay/cs_2');
        $newer->cancel(new \DateTimeImmutable('2026-11-05 12:08:00+00:00'));
        $this->payments->add($newer);

        self::assertEquals(new LatestPaymentView('demo-card', 'cancelled', null), $this->paymentStatus()->latestPayment);
    }

    public function testAPaidOrderShowsItsStatus(): void
    {
        $this->orders->add(self::order(OrderState::Paid, payable: false), PaymentAccess::guest(self::TOKEN));
        $payment = PaymentExamples::withSession();
        $payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable('2026-11-05 12:09:00+00:00'), new \DateTimeImmutable('2026-11-05 12:09:05+00:00'));
        $this->payments->add($payment);

        $status = $this->paymentStatus();

        self::assertSame('paid', $status->orderStatus);
        self::assertFalse($status->canStartPayment);
        self::assertEquals(new LatestPaymentView('demo-transfer', 'succeeded', null), $status->latestPayment);
    }

    public function testAWebhookCommittedBetweenTheTwoReadsNeverShowsASucceededPaymentOfAnUnpaidOrder(): void
    {
        $payment = PaymentExamples::withSession();
        $this->payments->add($payment);
        // The webhook pays the order and succeeds the payment in one transaction, so a reader sees both or neither;
        // here it commits once, right after whichever read comes first.
        $committed = false;
        $webhook = function () use (&$committed, $payment): void {
            if ($committed) {
                return;
            }
            $committed = true;
            $this->orders->add(self::order(OrderState::Paid, payable: false), PaymentAccess::guest(self::TOKEN));
            $payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable('2026-11-05 12:09:00+00:00'), new \DateTimeImmutable('2026-11-05 12:09:05+00:00'));
            $this->payments->save($payment);
        };
        $this->orders->afterRead = $webhook;
        $this->payments->afterLatestRead = $webhook;

        $status = $this->paymentStatus();

        self::assertTrue($committed, 'the webhook committed during the read');
        self::assertFalse(
            'new' === $status->orderStatus && 'succeeded' === $status->latestPayment?->status,
            'the order is read as unpaid, the payment as succeeded: the two reads saw different moments',
        );
    }

    /**
     * @return iterable<string, array{string, PaymentAccess}>
     */
    public static function withoutAccess(): iterable
    {
        yield 'unknown order' => ['0192f0c4-7c1a-7c4e-9a6e-0000000000ff', PaymentAccess::guest(self::TOKEN)];
        yield 'not a UUID' => ['R-100001', PaymentAccess::guest(self::TOKEN)];
        yield 'another token' => [PaymentExamples::ORDER, PaymentAccess::guest('another-token')];
        yield 'a customer' => [PaymentExamples::ORDER, PaymentAccess::customer('customer-1')];
    }

    #[DataProvider('withoutAccess')]
    public function testWithoutAccessToTheOrderItIsNotFound(string $orderId, PaymentAccess $access): void
    {
        $this->payments->add(PaymentExamples::withSession());

        $this->expectExceptionObject(new OrderNotFound());

        $this->paymentStatus($orderId, $access);
    }

    public function testTheProvidersAreListedWithTheirNames(): void
    {
        self::assertEquals(
            [new PaymentProviderView('demo-transfer', 'Szybki przelew (demo)'), new PaymentProviderView('demo-card', 'Karta płatnicza (demo)')],
            new ListPaymentProvidersHandler()(new ListPaymentProviders()),
        );
    }

    private function paymentStatus(string $orderId = PaymentExamples::ORDER, ?PaymentAccess $access = null): PaymentStatusView
    {
        return new GetPaymentStatusHandler($this->payments, $this->orders)(new GetPaymentStatus($orderId, $access ?? PaymentAccess::guest(self::TOKEN)));
    }

    private static function order(OrderState $state = OrderState::New, bool $payable = true): OrderToPay
    {
        return new OrderToPay(OrderId::fromString(PaymentExamples::ORDER), 'R-100001', $state, PaymentExamples::TOTAL, Currency::PLN, new \DateTimeImmutable(self::DEADLINE), $payable);
    }
}

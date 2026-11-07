<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\Command\HandleProviderWebhook;
use App\Payment\Application\Command\HandleProviderWebhookHandler;
use App\Payment\Application\OrderBusy;
use App\Payment\Application\OrderPaymentResult;
use App\Payment\Application\PaymentNotFound;
use App\Payment\Application\ProviderEvent;
use App\Payment\Domain\PaymentAmountMismatch;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Domain\RefundReason;
use App\Tests\Unit\Ordering\SpyLogger;
use App\Tests\Unit\Payment\FakeOrderPayments;
use App\Tests\Unit\Payment\FixedPaymentClock;
use App\Tests\Unit\Payment\InMemoryPayments;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * HandleProviderWebhook: the payment of the session under its lock, the deduplication by (provider, event id), the
 * success or refusal of the payment with the amount checked, the order paid through Ordering when the payment
 * succeeded just now, and the refund marked when the money cannot pay the order.
 */
final class HandleProviderWebhookHandlerTest extends TestCase
{
    private const string EXECUTED = '2026-11-05 12:19:30+00:00';
    private const string NOW = '2026-11-05 12:20:00+00:00';

    private InMemoryPayments $payments;
    private FakeOrderPayments $orders;
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->payments->add(PaymentExamples::withSession());
        $this->payments->journal = [];
        $this->orders = new FakeOrderPayments();
        $this->logger = new SpyLogger();
    }

    public function testASuccessSucceedsThePaymentAndPaysItsOrder(): void
    {
        $this->handle(self::success());

        $payment = $this->payments->get(PaymentExamples::PAYMENT);
        self::assertSame(PaymentStatus::Succeeded, $payment->status());
        self::assertEquals(new \DateTimeImmutable(self::EXECUTED), $payment->executedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
        self::assertNull($payment->refundRequiredReason());
        self::assertCount(1, $this->orders->calls);
        [$orderId, $paymentId, $amount, $executedAt] = $this->orders->calls[0];
        self::assertSame(PaymentExamples::ORDER, $orderId->toString());
        self::assertSame(PaymentExamples::PAYMENT, $paymentId->toString());
        self::assertTrue($amount->equals(PaymentExamples::amount()));
        self::assertEquals(new \DateTimeImmutable(self::EXECUTED), $executedAt);
        self::assertSame([
            'lock session demo-transfer '.PaymentExamples::SESSION,
            'event demo-transfer evt_1',
            'save '.PaymentExamples::PAYMENT.' succeeded',
        ], $this->payments->journal, 'the lock first, then the event, the payment saved last');
        self::assertSame(['demo-transfer evt_1' => PaymentExamples::PAYMENT], $this->payments->webhookEvents);
        self::assertSame([], $this->logger->records);
    }

    public function testAnUnknownSessionIsNotFoundAndNothingIsRecorded(): void
    {
        try {
            $this->handle(self::success(session: 'tx_unknown'));
            self::fail('A webhook of an unknown session was handled.');
        } catch (PaymentNotFound $notFound) {
            self::assertSame('Nie znaleźliśmy płatności.', $notFound->getMessage());
        }

        self::assertSame([], $this->payments->webhookEvents);
        self::assertSame([], $this->orders->calls);
    }

    public function testTheSessionIsLookedUpAtTheProviderOfTheWebhook(): void
    {
        $this->expectException(PaymentNotFound::class);

        $this->handle(self::success(), ProviderCode::DemoCard);
    }

    public function testARepeatedEventChangesNothing(): void
    {
        $this->handle(self::success());
        $this->payments->journal = [];

        $this->handle(self::success());

        self::assertCount(1, $this->orders->calls, 'the order is paid once');
        self::assertSame(['lock session demo-transfer '.PaymentExamples::SESSION], $this->payments->journal, 'no second write');
    }

    public function testARepeatedRefusalAfterAnotherEventChangesNothing(): void
    {
        $this->handle(self::refusal('evt_2'));
        $this->payments->journal = [];

        $this->handle(self::refusal('evt_2'));

        self::assertSame(['lock session demo-transfer '.PaymentExamples::SESSION], $this->payments->journal);
    }

    public function testARefusalFailsThePendingPaymentWithoutOrdering(): void
    {
        $this->handle(self::refusal());

        $payment = $this->payments->get(PaymentExamples::PAYMENT);
        self::assertSame(PaymentStatus::Failed, $payment->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
        self::assertSame([], $this->orders->calls);
        self::assertSame(['demo-transfer evt_2' => PaymentExamples::PAYMENT], $this->payments->webhookEvents, 'recorded too');
    }

    public function testARefusalAfterTheSuccessChangesNothing(): void
    {
        $this->handle(self::success());

        $this->handle(self::refusal());

        self::assertSame(PaymentStatus::Succeeded, $this->payments->get(PaymentExamples::PAYMENT)->status());
        self::assertCount(1, $this->orders->calls);
    }

    public function testASuccessAfterARefusalPaysTheOrder(): void
    {
        $this->handle(self::refusal());

        $this->handle(self::success());

        self::assertSame(PaymentStatus::Succeeded, $this->payments->get(PaymentExamples::PAYMENT)->status(), 'the money weighs more than the refusal');
        self::assertCount(1, $this->orders->calls);
    }

    public function testASecondSuccessOfASucceededPaymentDoesNotPayAgain(): void
    {
        $this->handle(self::success());

        $this->handle(self::success('evt_3', executedAt: '2026-11-05 12:25:00+00:00'));

        self::assertCount(1, $this->orders->calls);
        self::assertEquals(new \DateTimeImmutable(self::EXECUTED), $this->payments->get(PaymentExamples::PAYMENT)->executedAt(), 'the first execution stays');
    }

    public function testAnotherAmountIsRefusedLoggedAndNothingIsSaved(): void
    {
        try {
            $this->handle(self::success(amount: PaymentExamples::TOTAL + 1));
            self::fail('A payment of another amount succeeded.');
        } catch (PaymentAmountMismatch) {
        }

        self::assertSame(PaymentStatus::Pending, $this->payments->get(PaymentExamples::PAYMENT)->status());
        self::assertSame([], $this->orders->calls);
        self::assertNotContains('save '.PaymentExamples::PAYMENT.' succeeded', $this->payments->journal);
        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertSame(['provider' => 'demo-transfer', 'eventId' => 'evt_1', 'paymentId' => PaymentExamples::PAYMENT, 'amount' => PaymentExamples::TOTAL + 1, 'expected' => PaymentExamples::TOTAL], $context);
    }

    /**
     * @return iterable<string, array{OrderPaymentResult, ?RefundReason}>
     */
    public static function results(): iterable
    {
        yield 'paid' => [OrderPaymentResult::Paid, null];
        yield 'already paid by this payment' => [OrderPaymentResult::AlreadyPaid, null];
        yield 'paid by another payment' => [OrderPaymentResult::PaidByAnotherPayment, RefundReason::Duplicate];
        yield 'executed after the deadline' => [OrderPaymentResult::PaidAfterDeadline, RefundReason::PaidAfterDeadline];
        yield 'the order cancelled' => [OrderPaymentResult::OrderCancelled, RefundReason::OrderCancelled];
    }

    #[DataProvider('results')]
    public function testEveryResultOfOrderingKeepsThePaymentSucceededAndMarksItsRefund(OrderPaymentResult $result, ?RefundReason $refund): void
    {
        $this->orders->result = $result;

        $this->handle(self::success());

        $payment = $this->payments->get(PaymentExamples::PAYMENT);
        self::assertSame(PaymentStatus::Succeeded, $payment->status());
        self::assertSame($refund, $payment->refundRequiredReason());
        if (null === $refund) {
            self::assertSame([], $this->logger->records);

            return;
        }
        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertStringContainsString('manual refund', $message);
        self::assertSame(['provider' => 'demo-transfer', 'paymentId' => PaymentExamples::PAYMENT, 'orderId' => PaymentExamples::ORDER, 'reason' => $refund->value], $context);
    }

    public function testABusyOrderPassesOnAndThePaymentIsNotSaved(): void
    {
        $busy = new OrderBusy();
        $this->orders->failure = $busy;

        try {
            $this->handle(self::success());
            self::fail('The webhook ended while the order was busy.');
        } catch (OrderBusy $thrown) {
            self::assertSame($busy, $thrown);
        }

        self::assertSame(PaymentStatus::Pending, $this->payments->get(PaymentExamples::PAYMENT)->status());
    }

    public function testAnyOtherFailureOfOrderingPassesOnUnchanged(): void
    {
        $failure = new \RuntimeException('connection lost');
        $this->orders->failure = $failure;

        try {
            $this->handle(self::success());
            self::fail('The webhook ended while Ordering failed.');
        } catch (\RuntimeException $thrown) {
            self::assertSame($failure, $thrown);
        }

        self::assertSame(PaymentStatus::Pending, $this->payments->get(PaymentExamples::PAYMENT)->status());
    }

    private function handle(ProviderEvent $event, ProviderCode $provider = ProviderCode::DemoTransfer): void
    {
        $handler = new HandleProviderWebhookHandler($this->payments, $this->orders, new FixedPaymentClock(new \DateTimeImmutable(self::NOW)), $this->logger);

        $handler(new HandleProviderWebhook($provider, $event));
    }

    private static function success(string $eventId = 'evt_1', string $session = PaymentExamples::SESSION, int $amount = PaymentExamples::TOTAL, string $executedAt = self::EXECUTED): ProviderEvent
    {
        return ProviderEvent::succeeded(ProviderEventId::fromString($eventId), ProviderPaymentId::fromString($session), PaymentExamples::amount($amount), new \DateTimeImmutable($executedAt));
    }

    private static function refusal(string $eventId = 'evt_2'): ProviderEvent
    {
        return ProviderEvent::failed(ProviderEventId::fromString($eventId), ProviderPaymentId::fromString(PaymentExamples::SESSION), PaymentExamples::amount());
    }
}

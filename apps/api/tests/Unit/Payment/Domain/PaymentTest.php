<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Domain;

use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\InvalidPayment;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentAmountMismatch;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Domain\RefundReason;
use App\Payment\Domain\RequestFingerprint;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One attempt to pay an order: started pending, given the session of the provider once, then succeeded, failed or
 * cancelled. A payment that succeeded may need a refund; a pending one can be resumed while the order is payable.
 */
final class PaymentTest extends TestCase
{
    private const string NOW = '2026-11-05 12:20:00+00:00';
    private const string EXECUTED = '2026-11-05 12:19:30+00:00';

    public function testStartIsAPendingPaymentWithoutASession(): void
    {
        $payment = PaymentExamples::started(ProviderCode::DemoCard);

        self::assertSame(PaymentExamples::PAYMENT, $payment->id()->toString());
        self::assertSame(PaymentExamples::ORDER, $payment->orderId()->toString());
        self::assertSame(ProviderCode::DemoCard, $payment->provider());
        self::assertTrue($payment->amount()->equals(PaymentExamples::amount()));
        self::assertSame(PaymentStatus::Pending, $payment->status());
        self::assertSame(PaymentExamples::KEY, $payment->idempotencyKey()->toString());
        self::assertTrue($payment->requestFingerprint()->equals(RequestFingerprint::of(OrderId::fromString(PaymentExamples::ORDER), ProviderCode::DemoCard)));
        self::assertEquals(new \DateTimeImmutable(PaymentExamples::CREATED), $payment->createdAt());
        self::assertNull($payment->providerPaymentId());
        self::assertNull($payment->redirectUrl());
        self::assertNull($payment->completedAt());
        self::assertNull($payment->executedAt());
        self::assertNull($payment->refundRequiredReason());
    }

    public function testTheSessionOfTheProviderIsAttachedOnce(): void
    {
        $payment = PaymentExamples::started();

        $payment->attachProviderSession(ProviderPaymentId::fromString(PaymentExamples::SESSION), PaymentExamples::REDIRECT);
        $payment->attachProviderSession(ProviderPaymentId::fromString(PaymentExamples::SESSION), PaymentExamples::REDIRECT);

        self::assertSame(PaymentExamples::SESSION, $payment->providerPaymentId()?->toString());
        self::assertSame(PaymentExamples::REDIRECT, $payment->redirectUrl());
        self::assertSame(PaymentStatus::Pending, $payment->status());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherSessions(): iterable
    {
        yield 'another session' => ['tx_0002', PaymentExamples::REDIRECT];
        yield 'another address' => [PaymentExamples::SESSION, 'http://localhost:8000/payment-simulator/demo-transfer/pay/other'];
    }

    #[DataProvider('otherSessions')]
    public function testASecondSessionWithOtherDataIsInvalid(string $session, string $redirect): void
    {
        $payment = PaymentExamples::withSession();

        try {
            $payment->attachProviderSession(ProviderPaymentId::fromString($session), $redirect);
            self::fail('A second session was attached.');
        } catch (InvalidPayment) {
        }

        self::assertSame(PaymentExamples::SESSION, $payment->providerPaymentId()?->toString());
        self::assertSame(PaymentExamples::REDIRECT, $payment->redirectUrl());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRedirects(): iterable
    {
        yield 'empty' => [''];
        yield 'longer than 500 characters' => ['http://localhost/'.str_repeat('a', 484)];
    }

    #[DataProvider('invalidRedirects')]
    public function testARedirectAddressOutsideOneTo500CharactersIsInvalid(string $redirect): void
    {
        $this->expectException(InvalidPayment::class);

        PaymentExamples::started()->attachProviderSession(ProviderPaymentId::fromString(PaymentExamples::SESSION), $redirect);
    }

    public function testARedirectAddressOf500CharactersIsAccepted(): void
    {
        $redirect = 'http://localhost/'.str_repeat('a', 483);
        $payment = PaymentExamples::started();

        $payment->attachProviderSession(ProviderPaymentId::fromString(PaymentExamples::SESSION), $redirect);

        self::assertSame($redirect, $payment->redirectUrl());
    }

    public function testFailEndsAPendingPayment(): void
    {
        $payment = PaymentExamples::withSession();

        $payment->fail(new \DateTimeImmutable(self::NOW));

        self::assertSame(PaymentStatus::Failed, $payment->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
        self::assertNull($payment->executedAt());
    }

    public function testCancelEndsAPendingPayment(): void
    {
        $payment = PaymentExamples::withSession();

        $payment->cancel(new \DateTimeImmutable(self::NOW));

        self::assertSame(PaymentStatus::Cancelled, $payment->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
    }

    /**
     * @return iterable<string, array{\Closure(Payment): void, PaymentStatus}>
     */
    public static function endedPayments(): iterable
    {
        yield 'failed' => [static fn (Payment $p) => $p->fail(new \DateTimeImmutable('2026-11-05 12:10:00+00:00')), PaymentStatus::Failed];
        yield 'cancelled' => [static fn (Payment $p) => $p->cancel(new \DateTimeImmutable('2026-11-05 12:10:00+00:00')), PaymentStatus::Cancelled];
        yield 'succeeded' => [static function (Payment $p): void {
            $p->succeed(PaymentExamples::amount(), new \DateTimeImmutable('2026-11-05 12:09:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:00+00:00'));
        }, PaymentStatus::Succeeded];
    }

    /**
     * @param \Closure(Payment): void $end
     */
    #[DataProvider('endedPayments')]
    public function testFailAndCancelChangeNothingOnceThePaymentEnded(\Closure $end, PaymentStatus $status): void
    {
        $payment = PaymentExamples::withSession();
        $end($payment);

        $payment->fail(new \DateTimeImmutable(self::NOW));
        $payment->cancel(new \DateTimeImmutable(self::NOW));

        self::assertSame($status, $payment->status());
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), $payment->completedAt());
    }

    /**
     * @return iterable<string, array{\Closure(Payment): void}>
     */
    public static function notSucceeded(): iterable
    {
        yield 'pending' => [static function (Payment $p): void {
        }];
        yield 'failed' => [static fn (Payment $p) => $p->fail(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'))];
        yield 'cancelled' => [static fn (Payment $p) => $p->cancel(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'))];
    }

    /**
     * Money weighs more than an earlier refusal or cancellation.
     *
     * @param \Closure(Payment): void $before
     */
    #[DataProvider('notSucceeded')]
    public function testSucceedFromPendingFailedOrCancelled(\Closure $before): void
    {
        $payment = PaymentExamples::withSession();
        $before($payment);

        self::assertTrue($payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable(self::EXECUTED), new \DateTimeImmutable(self::NOW)));

        self::assertSame(PaymentStatus::Succeeded, $payment->status());
        self::assertEquals(new \DateTimeImmutable(self::EXECUTED), $payment->executedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
    }

    public function testSucceedTwiceChangesNothing(): void
    {
        $payment = PaymentExamples::withSession();
        $payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable(self::EXECUTED), new \DateTimeImmutable(self::NOW));

        self::assertFalse($payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable('2026-11-05 12:21:00+00:00'), new \DateTimeImmutable('2026-11-05 12:22:00+00:00')));

        self::assertEquals(new \DateTimeImmutable(self::EXECUTED), $payment->executedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
    }

    public function testSucceedWithAnotherAmountIsAMismatchWithoutChange(): void
    {
        $payment = PaymentExamples::withSession();

        try {
            $payment->succeed(PaymentExamples::amount(12_344), new \DateTimeImmutable(self::EXECUTED), new \DateTimeImmutable(self::NOW));
            self::fail('Another amount succeeded.');
        } catch (PaymentAmountMismatch) {
        }

        self::assertSame(PaymentStatus::Pending, $payment->status());
        self::assertNull($payment->executedAt());
    }

    public function testTheReasonOfARefundIsSetOnceOnASucceededPayment(): void
    {
        $payment = PaymentExamples::withSession();
        $payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable(self::EXECUTED), new \DateTimeImmutable(self::NOW));

        $payment->requireRefund(RefundReason::OrderCancelled);
        $payment->requireRefund(RefundReason::Duplicate);

        self::assertSame(RefundReason::OrderCancelled, $payment->refundRequiredReason());
    }

    /**
     * @param \Closure(Payment): void $before
     */
    #[DataProvider('notSucceeded')]
    public function testOnlyASucceededPaymentNeedsARefund(\Closure $before): void
    {
        $payment = PaymentExamples::withSession();
        $before($payment);

        try {
            $payment->requireRefund(RefundReason::PaidAfterDeadline);
            self::fail('A payment that did not succeed needs a refund.');
        } catch (InvalidPayment) {
        }

        self::assertNull($payment->refundRequiredReason());
    }

    public function testAReplayIsTheSameStartWithTheSameKey(): void
    {
        $payment = PaymentExamples::started(ProviderCode::DemoTransfer);
        $order = OrderId::fromString(PaymentExamples::ORDER);

        self::assertTrue($payment->isReplayOf(RequestFingerprint::of($order, ProviderCode::DemoTransfer)));
        self::assertFalse($payment->isReplayOf(RequestFingerprint::of($order, ProviderCode::DemoCard)), 'another provider');
        self::assertFalse($payment->isReplayOf(RequestFingerprint::of(OrderId::fromString('0192f0c4-7c1a-7c4e-9a6e-0000000000d2'), ProviderCode::DemoTransfer)), 'another order');
    }

    public function testAPendingPaymentOfAPayableOrderCanBeResumed(): void
    {
        $payment = PaymentExamples::withSession();

        self::assertSame(PaymentExamples::REDIRECT, $payment->resumeUrl(true));
        self::assertNull($payment->resumeUrl(false), 'the order is no longer payable');
        self::assertNull(PaymentExamples::started()->resumeUrl(true), 'no session yet');
    }

    /**
     * @param \Closure(Payment): void $end
     */
    #[DataProvider('endedPayments')]
    public function testAnEndedPaymentCannotBeResumed(\Closure $end, PaymentStatus $status): void
    {
        $payment = PaymentExamples::withSession();
        $end($payment);

        self::assertSame($status, $payment->status());
        self::assertNull($payment->resumeUrl(true));
    }

    public function testReconstituteKeepsEveryField(): void
    {
        $payment = Payment::reconstitute(
            id: PaymentId::fromString(PaymentExamples::PAYMENT),
            orderId: OrderId::fromString(PaymentExamples::ORDER),
            provider: ProviderCode::DemoCard,
            amount: PaymentExamples::amount(),
            status: PaymentStatus::Succeeded,
            providerPaymentId: ProviderPaymentId::fromString(PaymentExamples::SESSION),
            redirectUrl: PaymentExamples::REDIRECT,
            idempotencyKey: IdempotencyKey::fromString(PaymentExamples::KEY),
            requestFingerprint: RequestFingerprint::of(OrderId::fromString(PaymentExamples::ORDER), ProviderCode::DemoCard),
            createdAt: new \DateTimeImmutable(PaymentExamples::CREATED),
            completedAt: new \DateTimeImmutable(self::NOW),
            executedAt: new \DateTimeImmutable(self::EXECUTED),
            refundRequiredReason: RefundReason::Duplicate,
        );

        self::assertSame(PaymentStatus::Succeeded, $payment->status());
        self::assertSame(PaymentExamples::SESSION, $payment->providerPaymentId()?->toString());
        self::assertEquals(new \DateTimeImmutable(self::EXECUTED), $payment->executedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
        self::assertSame(RefundReason::Duplicate, $payment->refundRequiredReason());
    }
}

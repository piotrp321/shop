<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\PaymentProviders;
use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\PendingPaymentClosing;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Tests\Unit\Payment\FakePaymentProvider;
use App\Tests\Unit\Payment\FixedPaymentClock;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one place that maps the answer of a provider to the cancellation of a session onto a pending payment (spec:
 * docs/features/async-reliability/spec.md, W6), used by StartPayment and payment:close-abandoned: cancelled there →
 * cancelled, rejected there → failed, paid there → unchanged (its webhook confirms it). The caller saves.
 */
final class PendingPaymentClosingTest extends TestCase
{
    private const string NOW = '2026-11-05 12:50:00+00:00';

    private FakePaymentProvider $transfer;
    private PendingPaymentClosing $closing;

    protected function setUp(): void
    {
        $this->transfer = new FakePaymentProvider(ProviderCode::DemoTransfer);
        $this->closing = new PendingPaymentClosing(new PaymentProviders([$this->transfer, new FakePaymentProvider(ProviderCode::DemoCard)]), new FixedPaymentClock(new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{SessionCancellation, PaymentStatus, ?string}>
     */
    public static function answers(): iterable
    {
        yield 'cancelled at the provider' => [SessionCancellation::Cancelled, PaymentStatus::Cancelled, self::NOW];
        yield 'rejected at the provider' => [SessionCancellation::AlreadyRejected, PaymentStatus::Failed, self::NOW];
        yield 'paid at the provider' => [SessionCancellation::AlreadyPaid, PaymentStatus::Pending, null];
    }

    #[DataProvider('answers')]
    public function testTheAnswerOfTheProviderEndsThePayment(SessionCancellation $answer, PaymentStatus $status, ?string $completedAt): void
    {
        $this->transfer->cancellation = $answer;
        $payment = PaymentExamples::withSession();

        self::assertSame($answer, $this->closing->close($payment));

        self::assertSame([PaymentExamples::SESSION], $this->transfer->cancelled);
        self::assertSame($status, $payment->status());
        self::assertEquals(null === $completedAt ? null : new \DateTimeImmutable($completedAt), $payment->completedAt());
    }

    public function testAPaymentWithoutASessionIsCancelledWithoutAskingTheProvider(): void
    {
        $payment = PaymentExamples::started();

        self::assertSame(SessionCancellation::Cancelled, $this->closing->close($payment));

        self::assertSame([], $this->transfer->cancelled);
        self::assertSame(PaymentStatus::Cancelled, $payment->status());
    }

    public function testAnUnavailableProviderChangesNothing(): void
    {
        $this->transfer->cancelFails = true;
        $payment = PaymentExamples::withSession();

        try {
            $this->closing->close($payment);
            self::fail('Expected PaymentProviderUnavailable.');
        } catch (PaymentProviderUnavailable) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(PaymentStatus::Pending, $payment->status());
        self::assertNull($payment->completedAt());
    }
}

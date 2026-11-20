<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\Command\AbandonedPaymentOutcome;
use App\Payment\Application\Command\CloseAbandonedPayment;
use App\Payment\Application\Command\CloseAbandonedPaymentHandler;
use App\Payment\Application\PaymentProviders;
use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Tests\Unit\Ordering\SpyLogger;
use App\Tests\Unit\Payment\FakePaymentProvider;
use App\Tests\Unit\Payment\FixedPaymentClock;
use App\Tests\Unit\Payment\InMemoryPayments;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\TestCase;

/**
 * CloseAbandonedPayment, one pending payment of an order Ordering no longer lets pay, chosen by payment:close-abandoned
 * (spec: docs/features/async-reliability/spec.md, D5, W6). A cancellation at the provider is not undone by any rollback,
 * so the session is cancelled only under the lock of the payment and only when the locked payment is still the pending
 * one that was chosen: a webhook or a new start committed between the choice and the lock wins, and the provider is not
 * called. A session paid at the provider is left to its webhook.
 */
final class CloseAbandonedPaymentHandlerTest extends TestCase
{
    private const string NOW = '2026-11-05 12:50:00+00:00';
    private const string NEW_PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000a2';

    private InMemoryPayments $payments;
    private FakePaymentProvider $transfer;
    private FakePaymentProvider $card;
    private FixedPaymentClock $clock;
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->payments->add(PaymentExamples::withSession());
        $this->payments->journal = [];
        $this->transfer = new FakePaymentProvider(ProviderCode::DemoTransfer);
        $this->card = new FakePaymentProvider(ProviderCode::DemoCard);
        $this->clock = new FixedPaymentClock(new \DateTimeImmutable(self::NOW));
        $this->logger = new SpyLogger();
    }

    public function testThePendingPaymentIsCancelledAtItsProviderOnlyUnderItsLock(): void
    {
        $this->payments->whileWaitingForTheLock = function (): void {
            self::assertSame([], $this->transfer->cancelled, 'nothing is cancelled at the provider before the lock');
        };

        self::assertSame(AbandonedPaymentOutcome::Cancelled, $this->close());

        self::assertSame([PaymentExamples::SESSION], $this->transfer->cancelled);
        $payment = $this->payments->get(PaymentExamples::PAYMENT);
        self::assertSame(PaymentStatus::Cancelled, $payment->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $payment->completedAt());
        self::assertSame(['lock '.PaymentExamples::ORDER, 'save '.PaymentExamples::PAYMENT.' cancelled'], $this->payments->journal);
    }

    public function testASessionTheProviderRejectedFailsThePayment(): void
    {
        $this->transfer->cancellation = SessionCancellation::AlreadyRejected;

        self::assertSame(AbandonedPaymentOutcome::Failed, $this->close());

        self::assertSame(PaymentStatus::Failed, $this->payments->get(PaymentExamples::PAYMENT)->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->payments->get(PaymentExamples::PAYMENT)->completedAt());
    }

    public function testASessionPaidAtTheProviderStaysPendingForItsWebhookWithAWarning(): void
    {
        $this->transfer->cancellation = SessionCancellation::AlreadyPaid;

        self::assertSame(AbandonedPaymentOutcome::PaidAtProvider, $this->close());

        self::assertSame(PaymentStatus::Pending, $this->payments->get(PaymentExamples::PAYMENT)->status());
        self::assertSame(['lock '.PaymentExamples::ORDER], $this->payments->journal, 'nothing saved');
        self::assertCount(1, $this->logger->records);
        [$level, , $context] = $this->logger->records[0];
        self::assertSame('warning', $level);
        self::assertSame(['paymentId' => PaymentExamples::PAYMENT, 'orderId' => PaymentExamples::ORDER, 'provider' => 'demo-transfer'], $context);
    }

    public function testAWebhookCommittedBetweenTheChoiceAndTheLockWinsAndTheProviderIsNotCalled(): void
    {
        $this->payments->whileWaitingForTheLock = function (): void {
            $payment = $this->payments->get(PaymentExamples::PAYMENT);
            $payment->succeed(PaymentExamples::amount(), new \DateTimeImmutable('2026-11-05 12:29:00+00:00'), new \DateTimeImmutable('2026-11-05 12:49:59+00:00'));
            $this->payments->save($payment);
        };

        self::assertSame(AbandonedPaymentOutcome::NoLongerPending, $this->close());

        self::assertSame([], $this->transfer->cancelled);
        self::assertSame(PaymentStatus::Succeeded, $this->payments->get(PaymentExamples::PAYMENT)->status());
    }

    public function testANewStartCommittedBetweenTheChoiceAndTheLockWinsAndItsSessionIsNotCancelled(): void
    {
        $this->payments->whileWaitingForTheLock = function (): void {
            $this->payments->whileWaitingForTheLock = null;
            // The start cancelled the chosen payment at its provider and wrote its own pending payment with a session.
            $chosen = $this->payments->get(PaymentExamples::PAYMENT);
            $chosen->cancel(new \DateTimeImmutable('2026-11-05 12:49:58+00:00'));
            $this->payments->save($chosen);
            $this->payments->add(PaymentExamples::withSession(ProviderCode::DemoCard, self::NEW_PAYMENT, 'pay-0192f0c4-0002', 'card_0002'));
        };

        self::assertSame(AbandonedPaymentOutcome::NoLongerPending, $this->close());

        self::assertSame([], $this->transfer->cancelled);
        self::assertSame([], $this->card->cancelled, 'the session of the new start stays open');
        self::assertSame(PaymentStatus::Pending, $this->payments->get(self::NEW_PAYMENT)->status());
    }

    public function testAPaymentNoLongerPendingIsLeftWithoutCallingTheProvider(): void
    {
        $payment = $this->payments->get(PaymentExamples::PAYMENT);
        $payment->fail(new \DateTimeImmutable('2026-11-05 12:40:00+00:00'));
        $this->payments->save($payment);
        $this->payments->journal = [];

        self::assertSame(AbandonedPaymentOutcome::NoLongerPending, $this->close());

        self::assertSame([], $this->transfer->cancelled);
        self::assertSame(['lock '.PaymentExamples::ORDER], $this->payments->journal);
    }

    public function testAnUnavailableProviderIsThrownAndNothingIsSaved(): void
    {
        $this->transfer->cancelFails = true;

        try {
            $this->close();
            self::fail('Expected PaymentProviderUnavailable.');
        } catch (PaymentProviderUnavailable) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(PaymentStatus::Pending, $this->payments->get(PaymentExamples::PAYMENT)->status());
        self::assertSame(['lock '.PaymentExamples::ORDER], $this->payments->journal);
    }

    private function close(): AbandonedPaymentOutcome
    {
        $handler = new CloseAbandonedPaymentHandler($this->payments, new PaymentProviders([$this->transfer, $this->card]), $this->clock, $this->logger);

        return $handler(new CloseAbandonedPayment(PaymentExamples::ORDER, PaymentExamples::PAYMENT));
    }
}

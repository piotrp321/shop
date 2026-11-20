<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\Command\StartedPayment;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\Command\StartPaymentHandler;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\PaymentProviders;
use App\Payment\Application\Port\PaymentStartLock;
use App\Payment\Domain\Currency;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderState;
use App\Payment\Domain\OrderToPay;
use App\Payment\Domain\PaymentIdempotencyKeyReused;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Tests\Unit\Payment\FakePaymentProvider;
use App\Tests\Unit\Payment\FixedPaymentClock;
use App\Tests\Unit\Payment\InMemoryPayableOrders;
use App\Tests\Unit\Payment\InMemoryPayments;
use App\Tests\Unit\Payment\InMemoryPaymentStartLocks;
use App\Tests\Unit\Payment\PaymentExamples;
use PHPUnit\Framework\TestCase;

/**
 * StartPayment, the race of two starts with the same key for one order: the start B looks the key up and finds
 * nothing, the start A then commits its pending payment with the session at the provider and releases the lock of the
 * order, and B takes the free lock. B must look the key up again under the lock: it gives the replay of A (or the
 * conflict of a reused key) and never cancels the session of A at the provider, which no rollback would undo.
 */
final class StartPaymentReplayRaceTest extends TestCase
{
    private const string TOKEN = 'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM';
    private const string STORE = 'http://localhost:3000';

    private InMemoryPayments $payments;
    private InMemoryPayableOrders $orders;
    private FakePaymentProvider $transfer;
    private FakePaymentProvider $card;
    private PaymentProviders $providers;
    private FixedPaymentClock $clock;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->orders = new InMemoryPayableOrders();
        $this->orders->add(new OrderToPay(OrderId::fromString(PaymentExamples::ORDER), 'R-100001', OrderState::New, PaymentExamples::TOTAL, Currency::PLN, new \DateTimeImmutable('2026-11-05 12:30:00+00:00'), true), PaymentAccess::guest(self::TOKEN));
        $this->transfer = new FakePaymentProvider(ProviderCode::DemoTransfer);
        $this->card = new FakePaymentProvider(ProviderCode::DemoCard);
        $this->providers = new PaymentProviders([$this->transfer, $this->card]);
        $this->clock = new FixedPaymentClock(new \DateTimeImmutable('2026-11-05 12:10:00+00:00'));
    }

    public function testTheSameRequestCommittedBetweenTheLookupAndTheLockIsAReplayWithoutCancellingItsSession(): void
    {
        $startB = $this->startBWhileAStartsBetweenItsLookupAndLock('demo-transfer');

        $replayed = null;
        try {
            $replayed = $startB();
        } finally {
            self::assertSame([], $this->transfer->cancelled, 'the session of the start that committed first is not cancelled');
        }

        self::assertTrue($replayed->replayed);
        self::assertSame('pending', $replayed->payment->status);
        self::assertSame('http://provider.test/pay/demo_transfer_1', $replayed->payment->redirectUrl);
        $this->assertOnlyThePaymentOfAIsLeft();
    }

    public function testTheKeyOfAnotherRequestCommittedBetweenTheLookupAndTheLockIsReusedWithoutCancellingItsSession(): void
    {
        $startB = $this->startBWhileAStartsBetweenItsLookupAndLock('demo-card');

        try {
            $startB();
            self::fail('The key of another payment started one.');
        } catch (PaymentIdempotencyKeyReused) {
        } finally {
            self::assertSame([], $this->transfer->cancelled, 'the session of the start that committed first is not cancelled');
        }

        self::assertSame([], $this->card->created);
        self::assertSame([], $this->card->cancelled);
        $this->assertOnlyThePaymentOfAIsLeft();
    }

    /**
     * @return \Closure(): StartedPayment the start B (the provider as given, the key of A); the start A (demo-transfer)
     *                                    commits between its lookup of the key and its lock
     */
    private function startBWhileAStartsBetweenItsLookupAndLock(string $providerOfB): \Closure
    {
        $access = PaymentAccess::guest(self::TOKEN);
        $handlerA = $this->handler(new InMemoryPaymentStartLocks());
        $startA = new StartPayment(PaymentExamples::ORDER, $access, 'demo-transfer', PaymentExamples::KEY);
        $lockOfB = new class(static fn () => $handlerA($startA)) implements PaymentStartLock {
            private bool $committedTheOtherStart = false;

            public function __construct(private readonly \Closure $commitTheOtherStart)
            {
            }

            public function tryAcquire(OrderId $orderId): bool
            {
                if (!$this->committedTheOtherStart) {
                    $this->committedTheOtherStart = true;
                    // The start A runs to its commit after the lookup of B and before the lock of B.
                    ($this->commitTheOtherStart)();
                }

                return true;
            }
        };
        $handlerB = $this->handler($lockOfB);
        $startB = new StartPayment(PaymentExamples::ORDER, $access, $providerOfB, PaymentExamples::KEY);

        return static fn (): StartedPayment => $handlerB($startB);
    }

    private function assertOnlyThePaymentOfAIsLeft(): void
    {
        $stored = $this->payments->all();
        self::assertCount(1, $stored);
        self::assertSame(PaymentStatus::Pending, $stored[0]->status());
        self::assertSame('demo_transfer_1', $stored[0]->providerPaymentId()?->toString());
        self::assertCount(1, $this->transfer->created);
    }

    private function handler(PaymentStartLock $lock): StartPaymentHandler
    {
        return new StartPaymentHandler($this->payments, $this->orders, $this->providers, $this->clock, $lock, self::STORE);
    }
}

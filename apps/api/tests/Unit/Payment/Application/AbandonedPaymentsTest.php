<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Application;

use App\Payment\Application\AbandonedPayments;
use App\Payment\Application\OrderPayability;
use App\Payment\Application\Port\PendingPayment;
use App\Payment\Domain\Amount;
use App\Payment\Domain\Currency;
use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderState;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\RequestFingerprint;
use App\Tests\Unit\Payment\InMemoryPayableOrders;
use App\Tests\Unit\Payment\InMemoryPayments;
use PHPUnit\Framework\TestCase;

/**
 * The pending payments payment:close-abandoned closes (spec: docs/features/async-reliability/spec.md, D5, W6): read in
 * batches by the keyset of the order id, with one question to Ordering about the payability of the orders of the whole
 * batch; a payment of an order that can still be paid is left, a payment of an order Ordering no longer lets pay
 * (cancelled, paid, new past the deadline, or unknown) is abandoned.
 */
final class AbandonedPaymentsTest extends TestCase
{
    private InMemoryPayments $payments;
    private InMemoryPayableOrders $orders;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->orders = new InMemoryPayableOrders();
    }

    public function testOnlyThePaymentsOfOrdersThatCanNoLongerBePaidAreAbandoned(): void
    {
        $payable = $this->pending(1, OrderState::New, true);
        $pastDeadline = $this->pending(2, OrderState::New, false);
        $cancelled = $this->pending(3, OrderState::Cancelled, false);
        $paid = $this->pending(4, OrderState::Paid, false);
        $unknown = $this->pending(5, null, false);

        $batches = $this->abandoned(100);

        self::assertEquals([[$pastDeadline, $cancelled, $paid, $unknown]], $batches);
        self::assertNotContains($payable, $batches[0]);
    }

    public function testOneQuestionToOrderingPerBatchWithTheOrdersOfTheBatch(): void
    {
        $first = $this->pending(1, OrderState::New, false);
        $second = $this->pending(2, OrderState::New, true);
        $third = $this->pending(3, OrderState::Cancelled, false);

        $batches = $this->abandoned(2);

        self::assertEquals([[$first], [$third]], $batches);
        self::assertSame([
            [self::orderId(1), self::orderId(2)],
            [self::orderId(3)],
        ], $this->orders->payabilityAsked);
        self::assertSame([[null, 2], [self::orderId(2), 2], [self::orderId(3), 2]], $this->payments->pendingPages, 'the keyset is the last order read, until an empty page');
    }

    public function testAPageOnlyOfPayableOrdersIsAnEmptyBatchAndTheWalkGoesOn(): void
    {
        $this->pending(1, OrderState::New, true);
        $abandoned = $this->pending(2, OrderState::Cancelled, false);

        $batches = $this->abandoned(1);

        self::assertEquals([[], [$abandoned]], $batches);
    }

    public function testThePaymentOfAnOrderOrderingCouldNotReadIsReportedApartAndNotAbandoned(): void
    {
        $abandoned = $this->pending(1, OrderState::Cancelled, false);
        $unreadable = $this->pending(2, null, false);
        $this->orders->unreadable[self::orderId(2)] = 'InvalidArgumentException: A tax class has 1 to 20 characters.';

        $batches = iterator_to_array(new AbandonedPayments($this->payments, $this->orders)->batches(100), false);

        self::assertCount(1, $batches);
        self::assertEquals([$abandoned], $batches[0]->abandoned);
        self::assertEquals([[$unreadable, 'InvalidArgumentException: A tax class has 1 to 20 characters.']], $batches[0]->unreadable);
    }

    public function testNoPendingPaymentAsksOrderingNothing(): void
    {
        $payment = $this->payment(1);
        $payment->cancel(new \DateTimeImmutable('2026-11-05 12:40:00+00:00'));
        $this->payments->add($payment);

        self::assertSame([], $this->abandoned(100));
        self::assertSame([], $this->orders->payabilityAsked);
    }

    /**
     * @return list<list<PendingPayment>> the abandoned payments of each batch
     */
    private function abandoned(int $batchSize): array
    {
        $batches = [];
        foreach (new AbandonedPayments($this->payments, $this->orders)->batches($batchSize) as $batch) {
            self::assertSame([], $batch->unreadable);
            $batches[] = $batch->abandoned;
        }

        return $batches;
    }

    /**
     * A pending payment of the order numbered $n, which Ordering knows in the state given (null: unknown to it).
     */
    private function pending(int $n, ?OrderState $state, bool $payable): PendingPayment
    {
        $payment = $this->payment($n);
        $this->payments->add($payment);
        if (null !== $state) {
            $this->orders->payability[self::orderId($n)] = new OrderPayability($payment->orderId(), $state, $payable);
        }

        return new PendingPayment($payment->orderId(), $payment->id());
    }

    private function payment(int $n): Payment
    {
        $orderId = OrderId::fromString(self::orderId($n));

        return Payment::start(
            PaymentId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-0000000001%02d', $n)),
            $orderId,
            ProviderCode::DemoTransfer,
            Amount::of(1_230, Currency::PLN),
            IdempotencyKey::fromString('pay-'.$n),
            RequestFingerprint::of($orderId, ProviderCode::DemoTransfer),
            new \DateTimeImmutable('2026-11-05 12:05:00+00:00'),
        );
    }

    private static function orderId(int $n): string
    {
        return \sprintf('0192f0c4-7c1a-7c4e-9a6e-0000000002%02d', $n);
    }
}

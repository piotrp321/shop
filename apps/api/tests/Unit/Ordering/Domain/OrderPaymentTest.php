<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\AccessClaim;
use App\Ordering\Domain\CancellationReason;
use App\Ordering\Domain\ExpiryResult;
use App\Ordering\Domain\InvalidOrder;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderPaid;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\PaymentResult;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The payment cycle of an order (new → paid, new → cancelled): the deadline, the access of a guest by the token and
 * of a customer by the id, paying (by the moment the provider executed the payment) and the cancellation of an order
 * left unpaid.
 */
final class OrderPaymentTest extends TestCase
{
    private const string PAYMENT_ID = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string FIRST = self::PAYMENT_ID;
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-0000000000b2';

    public function testTheDeadlineIsThirtyMinutesAfterThePlacement(): void
    {
        $order = OrderExamples::placed();

        self::assertSame(OrderStatus::New, $order->status());
        self::assertEquals(new \DateTimeImmutable(OrderExamples::DEADLINE), $order->paymentDeadline());
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:35:00+00:00'), $order->expiryDueAt(), 'five minutes for the webhooks after the deadline');
        self::assertNull($order->paidAt());
        self::assertNull($order->paymentId());
        self::assertNull($order->cancelledAt());
        self::assertNull($order->cancellationReason());
    }

    public function testAGuestOrderHasTheTokenAndACustomerOrderNone(): void
    {
        self::assertSame(OrderExamples::TOKEN, OrderExamples::placed()->accessToken()?->toString());
        self::assertNull(OrderExamples::placed(byCustomer: true)->accessToken());
    }

    public function testAGuestOrderWithoutATokenOrACustomerOrderWithOneIsInvalid(): void
    {
        $guest = OrderExamples::placed();
        $customer = OrderExamples::placed(byCustomer: true);

        foreach ([[$guest->owner(), null], [$customer->owner(), $guest->accessToken()]] as [$owner, $token]) {
            try {
                Order::place($guest->id(), $guest->number(), $owner, $token, $guest->contact(), $guest->shippingAddress(), $guest->lines(), null, $guest->idempotencyKey(), $guest->requestFingerprint(), $guest->placedAt());
                self::fail('Expected InvalidOrder.');
            } catch (InvalidOrder) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return iterable<string, array{bool, AccessClaim, bool}> whether the order is a customer's, the claim, granted
     */
    public static function claims(): iterable
    {
        yield 'guest order, its token' => [false, AccessClaim::guest(OrderExamples::TOKEN), true];
        yield 'guest order, another token' => [false, AccessClaim::guest('eGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM'), false];
        yield 'guest order, the token with one character less' => [false, AccessClaim::guest(substr(OrderExamples::TOKEN, 0, -1)), false];
        yield 'guest order, an empty token' => [false, AccessClaim::guest(''), false];
        yield 'guest order, a customer' => [false, AccessClaim::customer(OrderExamples::CUSTOMER), false];
        yield 'customer order, the customer' => [true, AccessClaim::customer(OrderExamples::CUSTOMER), true];
        yield 'customer order, another customer' => [true, AccessClaim::customer('kc-sub-2'), false];
        yield 'customer order, a guest token' => [true, AccessClaim::guest(OrderExamples::TOKEN), false];
    }

    #[DataProvider('claims')]
    public function testGrantsAccessToTheGuestByTheTokenAndToTheCustomerById(bool $byCustomer, AccessClaim $claim, bool $granted): void
    {
        self::assertSame($granted, OrderExamples::placed($byCustomer)->grantsAccess($claim));
    }

    public function testIsPayableWhileNewAndBeforeTheDeadline(): void
    {
        $order = OrderExamples::placed();

        self::assertTrue($order->isPayable(new \DateTimeImmutable(OrderExamples::PLACED_AT)));
        self::assertTrue($order->isPayable(new \DateTimeImmutable('2026-11-05 12:29:59+00:00')));
        self::assertFalse($order->isPayable(new \DateTimeImmutable(OrderExamples::DEADLINE)), 'the deadline itself is too late to start');
        self::assertFalse($order->isPayable(new \DateTimeImmutable('2026-11-05 12:31:00+00:00')));

        $paid = OrderExamples::placed();
        $paid->markPaid(self::paymentId(), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'));
        self::assertFalse($paid->isPayable(new \DateTimeImmutable('2026-11-05 12:11:00+00:00')));

        $cancelled = OrderExamples::placed();
        $cancelled->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00'));
        self::assertFalse($cancelled->isPayable(new \DateTimeImmutable('2026-11-05 12:11:00+00:00')));
    }

    public function testAPaymentExecutedBeforeTheDeadlinePaysTheOrder(): void
    {
        $order = OrderExamples::placed();
        $now = new \DateTimeImmutable('2026-11-05 12:33:00+00:00');

        $result = $order->markPaid(self::paymentId(), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:20:00+00:00'), $now);

        self::assertSame(PaymentResult::Paid, $result);
        self::assertSame(OrderStatus::Paid, $order->status());
        self::assertEquals($now, $order->paidAt(), 'paid when the webhook came, within the window after the deadline');
        self::assertSame(self::PAYMENT_ID, $order->paymentId()?->toString());
        self::assertEquals([new OrderPaid(OrderExamples::ORDER, $now)], $order->releaseEvents());
    }

    public function testAPaymentExecutedAtTheDeadlineItselfPaysTheOrder(): void
    {
        $order = OrderExamples::placed();

        $result = $order->markPaid(self::paymentId(), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable(OrderExamples::DEADLINE), new \DateTimeImmutable('2026-11-05 12:30:02+00:00'));

        self::assertSame(PaymentResult::Paid, $result);
        self::assertSame(OrderStatus::Paid, $order->status());
    }

    public function testAPaymentExecutedASecondAfterTheDeadlineLeavesTheOrderNew(): void
    {
        $order = OrderExamples::placed();

        $result = $order->markPaid(self::paymentId(), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:30:01+00:00'), new \DateTimeImmutable('2026-11-05 12:30:02+00:00'));

        self::assertSame(PaymentResult::PaidAfterDeadline, $result);
        self::assertUnchangedNew($order);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function wrongAmounts(): iterable
    {
        yield 'a grosz less' => [OrderExamples::TOTAL - 1, 'PLN'];
        yield 'a grosz more' => [OrderExamples::TOTAL + 1, 'PLN'];
        yield 'another currency' => [OrderExamples::TOTAL, 'EUR'];
        yield 'the currency in lower case' => [OrderExamples::TOTAL, 'pln'];
    }

    #[DataProvider('wrongAmounts')]
    public function testAnAmountOtherThanTheGrossTotalIsALogicError(int $amount, string $currency): void
    {
        $order = OrderExamples::placed();

        try {
            $order->markPaid(self::paymentId(), $amount, $currency, new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'));
            self::fail('Expected InvalidOrder.');
        } catch (InvalidOrder) {
            self::assertUnchangedNew($order);
        }
    }

    public function testThePaymentThatPaidTheOrderIsAlreadyPaidAndAnotherPaysItAgainWithoutAChange(): void
    {
        $order = OrderExamples::placed();
        $paidAt = new \DateTimeImmutable('2026-11-05 12:10:05+00:00');
        $order->markPaid(PaymentId::fromString(self::FIRST), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), $paidAt);
        $order->releaseEvents();

        $same = $order->markPaid(PaymentId::fromString(self::FIRST), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:11:00+00:00'));
        $another = $order->markPaid(PaymentId::fromString(self::OTHER), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:12:00+00:00'), new \DateTimeImmutable('2026-11-05 12:12:05+00:00'));

        self::assertSame(PaymentResult::AlreadyPaid, $same);
        self::assertSame(PaymentResult::PaidByAnotherPayment, $another);
        self::assertSame(OrderStatus::Paid, $order->status());
        self::assertEquals($paidAt, $order->paidAt());
        self::assertSame(self::FIRST, $order->paymentId()?->toString());
        self::assertSame([], $order->releaseEvents());
    }

    public function testACancelledOrderIsNeverPaid(): void
    {
        $order = OrderExamples::placed();
        $order->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00'));
        $order->releaseEvents();

        $result = $order->markPaid(self::paymentId(), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:20:00+00:00'), new \DateTimeImmutable('2026-11-05 12:36:00+00:00'));

        self::assertSame(PaymentResult::OrderCancelled, $result);
        self::assertSame(OrderStatus::Cancelled, $order->status());
        self::assertNull($order->paidAt());
        self::assertNull($order->paymentId());
        self::assertSame([], $order->releaseEvents());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function momentsBeforeTheExpiry(): iterable
    {
        yield 'right after the placement' => ['2026-11-05 12:00:01+00:00'];
        yield 'at the deadline' => [OrderExamples::DEADLINE];
        yield 'a second before deadline + 5 min' => ['2026-11-05 12:34:59+00:00'];
    }

    #[DataProvider('momentsBeforeTheExpiry')]
    public function testANewOrderIsNotCancelledBeforeDeadlinePlusFiveMinutes(string $now): void
    {
        $order = OrderExamples::placed();

        self::assertSame(ExpiryResult::TooEarly, $order->expireUnpaid(new \DateTimeImmutable($now)));
        self::assertUnchangedNew($order);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function momentsOfTheExpiry(): iterable
    {
        yield 'deadline + 5 min' => ['2026-11-05 12:35:00+00:00'];
        yield 'an hour later' => ['2026-11-05 13:35:00+00:00'];
    }

    #[DataProvider('momentsOfTheExpiry')]
    public function testANewOrderIsCancelledFromDeadlinePlusFiveMinutes(string $now): void
    {
        $order = OrderExamples::placed();
        $at = new \DateTimeImmutable($now);

        $result = $order->expireUnpaid($at);

        self::assertSame(ExpiryResult::Cancelled, $result);
        self::assertSame(OrderStatus::Cancelled, $order->status());
        self::assertEquals($at, $order->cancelledAt());
        self::assertSame(CancellationReason::PaymentTimeout, $order->cancellationReason());
        $events = $order->releaseEvents();
        self::assertEquals([new OrderCancelled(OrderExamples::ORDER, 'payment_timeout', $at)], $events);
        self::assertSame($at, $events[0]->occurredAt());
    }

    public function testAPaidOrAlreadyCancelledOrderIsNotApplicable(): void
    {
        $paid = OrderExamples::placed();
        $paid->markPaid(self::paymentId(), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'));
        $paid->releaseEvents();
        $cancelled = OrderExamples::placed();
        $cancelledAt = new \DateTimeImmutable('2026-11-05 12:35:00+00:00');
        $cancelled->expireUnpaid($cancelledAt);
        $cancelled->releaseEvents();

        foreach (['2026-11-05 12:20:00+00:00', '2026-11-05 13:00:00+00:00'] as $now) {
            self::assertSame(ExpiryResult::NotApplicable, $paid->expireUnpaid(new \DateTimeImmutable($now)));
            self::assertSame(ExpiryResult::NotApplicable, $cancelled->expireUnpaid(new \DateTimeImmutable($now)));
        }

        self::assertSame(OrderStatus::Paid, $paid->status());
        self::assertNull($paid->cancelledAt());
        self::assertSame(OrderStatus::Cancelled, $cancelled->status());
        self::assertEquals($cancelledAt, $cancelled->cancelledAt());
        self::assertSame([], $paid->releaseEvents());
        self::assertSame([], $cancelled->releaseEvents());
    }

    public function testReconstituteKeepsThePaymentState(): void
    {
        $paid = OrderExamples::placed();
        $paid->markPaid(self::paymentId(), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable('2026-11-05 12:10:05+00:00'));
        $cancelled = OrderExamples::placed(byCustomer: true);
        $cancelled->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00'));

        $paidCopy = OrderExamples::copy($paid, 4);
        $cancelledCopy = OrderExamples::copy($cancelled, 3);

        self::assertSame([OrderStatus::Paid, self::PAYMENT_ID, OrderExamples::TOKEN, 4], [$paidCopy->status(), $paidCopy->paymentId()?->toString(), $paidCopy->accessToken()?->toString(), $paidCopy->version()]);
        self::assertEquals([new \DateTimeImmutable('2026-11-05 12:10:05+00:00'), new \DateTimeImmutable(OrderExamples::DEADLINE)], [$paidCopy->paidAt(), $paidCopy->paymentDeadline()]);
        self::assertSame([OrderStatus::Cancelled, CancellationReason::PaymentTimeout, null, 3], [$cancelledCopy->status(), $cancelledCopy->cancellationReason(), $cancelledCopy->accessToken(), $cancelledCopy->version()]);
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:35:00+00:00'), $cancelledCopy->cancelledAt());
        self::assertSame([], $paidCopy->releaseEvents());
        self::assertSame([], $cancelledCopy->releaseEvents());
    }

    private static function paymentId(): PaymentId
    {
        return PaymentId::fromString(self::PAYMENT_ID);
    }

    private static function assertUnchangedNew(Order $order): void
    {
        self::assertSame(OrderStatus::New, $order->status());
        self::assertNull($order->paidAt());
        self::assertNull($order->paymentId());
        self::assertNull($order->cancelledAt());
        self::assertNull($order->cancellationReason());
        self::assertSame([], $order->releaseEvents());
    }
}

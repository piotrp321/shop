<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\CancellationReason;
use App\Ordering\Domain\ExpiryResult;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Domain\OrderReturned;
use App\Ordering\Domain\OrderShipped;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\OrderStatusChange;
use App\Ordering\Domain\OrderTransitionNotAllowed;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\PaymentResult;
use App\Ordering\Domain\RefundNotPending;
use App\Ordering\Domain\StaffActor;
use App\Ordering\Domain\StaffTransition;
use App\Ordering\Domain\StatusActor;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cycle of an order after its payment, driven by staff (ship, deliver, cancel, return) with the table of allowed
 * transitions, the history of the statuses, the refund that a cancellation and a return create, and the rules of the
 * note of a staff member.
 */
final class OrderLifecycleTest extends TestCase
{
    private const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string OTHER_PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b2';
    private const string PAID_AT = '2026-11-05 12:10:05+00:00';
    private const string SHIPPED_AT = '2026-11-06 09:00:00+00:00';
    private const string DELIVERED_AT = '2026-11-07 10:00:00+00:00';
    private const string NOW = '2026-11-08 11:00:00+00:00';

    public function testTheTableOfTransitionsIsTheOnlyRule(): void
    {
        self::assertSame([], OrderStatus::New->allowedStaffTransitions());
        self::assertSame([StaffTransition::Ship, StaffTransition::Cancel], OrderStatus::Paid->allowedStaffTransitions());
        self::assertSame([StaffTransition::Deliver, StaffTransition::Return], OrderStatus::Shipped->allowedStaffTransitions());
        self::assertSame([StaffTransition::Return], OrderStatus::Delivered->allowedStaffTransitions());
        self::assertSame([], OrderStatus::Cancelled->allowedStaffTransitions());
        self::assertSame([], OrderStatus::Returned->allowedStaffTransitions());
    }

    /**
     * @return iterable<string, array{string, StaffTransition, OrderStatus}> the state, the transition, the status after
     */
    public static function allowedTransitions(): iterable
    {
        yield 'paid, ship' => ['paid', StaffTransition::Ship, OrderStatus::Shipped];
        yield 'paid, cancel' => ['paid', StaffTransition::Cancel, OrderStatus::Cancelled];
        yield 'shipped, deliver' => ['shipped', StaffTransition::Deliver, OrderStatus::Delivered];
        yield 'shipped, return' => ['shipped', StaffTransition::Return, OrderStatus::Returned];
        yield 'delivered, return' => ['delivered', StaffTransition::Return, OrderStatus::Returned];
    }

    #[DataProvider('allowedTransitions')]
    public function testEveryRowOfTheTableChangesTheStatus(string $state, StaffTransition $transition, OrderStatus $to): void
    {
        $order = self::orderIn($state);
        $from = $order->status();

        self::apply($order, $transition, 'Notatka', new \DateTimeImmutable(self::NOW));

        self::assertSame($to, $order->status());
        $last = self::lastChange($order);
        self::assertEquals(new OrderStatusChange($from, $to, new \DateTimeImmutable(self::NOW), StatusActor::staff(self::staff()), 'Notatka'), $last);
        self::assertSame(1, $order->version(), 'the version is raised by the repository, not by the domain');
    }

    /**
     * @return iterable<string, array{string, StaffTransition}>
     */
    public static function transitionsOutsideTheTable(): iterable
    {
        $allowed = [
            'new' => [],
            'paid' => [StaffTransition::Ship, StaffTransition::Cancel],
            'shipped' => [StaffTransition::Deliver, StaffTransition::Return],
            'delivered' => [StaffTransition::Return],
            'expired' => [],
            'cancelled by staff' => [],
            'returned' => [],
        ];
        foreach ($allowed as $state => $transitions) {
            foreach (StaffTransition::cases() as $transition) {
                if (!\in_array($transition, $transitions, true)) {
                    yield $state.', '.$transition->value => [$state, $transition];
                }
            }
        }
    }

    #[DataProvider('transitionsOutsideTheTable')]
    public function testATransitionOutsideTheTableIsNotAllowedAndChangesNothing(string $state, StaffTransition $transition): void
    {
        $order = self::orderIn($state);
        $before = self::snapshot($order);

        try {
            self::apply($order, $transition, 'Notatka', new \DateTimeImmutable(self::NOW));
            self::fail('Expected OrderTransitionNotAllowed.');
        } catch (OrderTransitionNotAllowed $notAllowed) {
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/order-transition-not-allowed', $notAllowed->problemType());
            self::assertSame('Tej zmiany nie można wykonać w obecnym statusie zamówienia.', $notAllowed->getMessage());
        }

        self::assertEquals($before, self::snapshot($order));
        self::assertSame([], $order->releaseEvents());
    }

    public function testShipFromPaidSetsShippedAtAndRecordsOrderShipped(): void
    {
        $order = self::orderIn('paid');
        $now = new \DateTimeImmutable(self::SHIPPED_AT);

        $order->ship(self::staff(), null, $now);

        self::assertSame(OrderStatus::Shipped, $order->status());
        self::assertEquals($now, $order->shippedAt());
        self::assertNull($order->deliveredAt());
        self::assertNull($order->refund());
        self::assertEquals([new OrderShipped(OrderExamples::ORDER, $now)], $order->releaseEvents());
        self::assertSame(self::PAYMENT, $order->paymentId()?->toString(), 'the payment stays');
    }

    public function testDeliverFromShippedSetsDeliveredAtWithoutAnEvent(): void
    {
        $order = self::orderIn('shipped');
        $now = new \DateTimeImmutable(self::DELIVERED_AT);

        $order->deliver(self::staff(), 'Odebrane osobiście', $now);

        self::assertSame(OrderStatus::Delivered, $order->status());
        self::assertEquals(new \DateTimeImmutable(self::SHIPPED_AT), $order->shippedAt());
        self::assertEquals($now, $order->deliveredAt());
        self::assertSame([], $order->releaseEvents());
    }

    public function testCancelFromPaidSetsTheStaffReasonCreatesARefundDueInFourteenDaysAndRecordsOrderCancelled(): void
    {
        $order = self::orderIn('paid');
        $now = new \DateTimeImmutable(self::NOW);

        $order->cancel(self::staff(), '  Klient zrezygnował  ', $now);

        self::assertSame(OrderStatus::Cancelled, $order->status());
        self::assertEquals($now, $order->cancelledAt());
        self::assertSame(CancellationReason::Staff, $order->cancellationReason());
        self::assertEquals(new \DateTimeImmutable(self::PAID_AT), $order->paidAt(), 'paidAt and the payment stay after a cancellation');
        self::assertSame(self::PAYMENT, $order->paymentId()?->toString());
        $refund = $order->refund();
        self::assertNotNull($refund);
        self::assertSame(OrderExamples::TOTAL, $refund->amount()->amount(), 'the gross total of the order');
        self::assertEquals(new \DateTimeImmutable('2026-11-22 11:00:00+00:00'), $refund->dueAt());
        self::assertTrue($refund->isPending());
        self::assertNull($refund->completedAt());
        self::assertNull($refund->completedBy());
        self::assertNull($refund->note());
        self::assertSame('Klient zrezygnował', self::lastChange($order)->note(), 'the note is trimmed');
        $events = $order->releaseEvents();
        self::assertEquals([new OrderCancelled(OrderExamples::ORDER, 'staff', $now)], $events);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function returnableStates(): iterable
    {
        yield 'shipped (a parcel that came back undelivered)' => ['shipped'];
        yield 'delivered' => ['delivered'];
    }

    #[DataProvider('returnableStates')]
    public function testAReturnCreatesARefundAndRecordsOrderReturned(string $state): void
    {
        $order = self::orderIn($state);
        $deliveredAt = $order->deliveredAt();
        $now = new \DateTimeImmutable(self::NOW);

        $order->registerReturn(self::staff(), 'Paczka wróciła', $now);

        self::assertSame(OrderStatus::Returned, $order->status());
        self::assertEquals($now, $order->returnedAt());
        self::assertEquals($deliveredAt, $order->deliveredAt(), 'a return from shipped leaves deliveredAt null');
        self::assertEquals(new \DateTimeImmutable(self::SHIPPED_AT), $order->shippedAt());
        self::assertNull($order->cancellationReason());
        $refund = $order->refund();
        self::assertNotNull($refund);
        self::assertSame(OrderExamples::TOTAL, $refund->amount()->amount());
        self::assertEquals($now->modify('+14 days'), $refund->dueAt());
        self::assertTrue($refund->isPending());
        self::assertEquals([new OrderReturned(OrderExamples::ORDER, $now)], $order->releaseEvents());
    }

    public function testCompleteRefundCompletesAPendingRefundOnceWithoutAHistoryEntry(): void
    {
        $order = self::orderIn('cancelled by staff');
        $history = $order->history();
        $completer = StaffActor::of('kc-staff-2', 'bartek');
        $now = new \DateTimeImmutable('2026-11-10 08:00:00+00:00');

        $order->completeRefund($completer, ' Przelew wysłany ', $now);

        $refund = $order->refund();
        self::assertNotNull($refund);
        self::assertFalse($refund->isPending());
        self::assertEquals($now, $refund->completedAt());
        self::assertEquals($completer, $refund->completedBy());
        self::assertSame('Przelew wysłany', $refund->note());
        self::assertSame(OrderExamples::TOTAL, $refund->amount()->amount());
        self::assertEquals(new \DateTimeImmutable('2026-11-22 11:00:00+00:00'), $refund->dueAt(), 'the due date stays');
        self::assertSame(OrderStatus::Cancelled, $order->status(), 'the status stays');
        self::assertEquals($history, $order->history(), 'no history entry');
        self::assertSame([], $order->releaseEvents());

        try {
            $order->completeRefund($completer, null, $now->modify('+1 hour'));
            self::fail('Expected RefundNotPending.');
        } catch (RefundNotPending $notPending) {
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/refund-not-pending', $notPending->problemType());
            self::assertSame('To zamówienie nie ma zwrotu do wykonania.', $notPending->getMessage());
        }
        self::assertEquals($now, $order->refund()?->completedAt(), 'the first completion stays');
    }

    public function testACompletedRefundWithANoteOfNonBreakingSpacesHasNone(): void
    {
        $order = self::orderIn('returned');

        $order->completeRefund(self::staff(), "\u{00A0}\u{00A0}", new \DateTimeImmutable(self::NOW));

        self::assertNull($order->refund()?->note());
    }

    public function testACompletedRefundWithAnEmptyNoteHasNone(): void
    {
        $order = self::orderIn('returned');

        $order->completeRefund(self::staff(), '   ', new \DateTimeImmutable(self::NOW));

        self::assertNull($order->refund()?->note());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesWithoutARefund(): iterable
    {
        foreach (['new', 'paid', 'shipped', 'delivered', 'expired'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('statesWithoutARefund')]
    public function testCompleteRefundWithoutARefundIsRefundNotPending(string $state): void
    {
        $order = self::orderIn($state);
        $before = self::snapshot($order);

        try {
            $order->completeRefund(self::staff(), null, new \DateTimeImmutable(self::NOW));
            self::fail('Expected RefundNotPending.');
        } catch (RefundNotPending) {
            $this->addToAssertionCount(1);
        }
        self::assertEquals($before, self::snapshot($order));
    }

    public function testARefundIsOverdueOnlyAfterItsDueDateWhilePending(): void
    {
        $order = self::orderIn('cancelled by staff');
        $refund = $order->refund();
        self::assertNotNull($refund);

        self::assertFalse($refund->isOverdue(new \DateTimeImmutable('2026-11-22 11:00:00+00:00')), 'the due date itself is not overdue');
        self::assertTrue($refund->isOverdue(new \DateTimeImmutable('2026-11-22 11:00:01+00:00')));

        $order->completeRefund(self::staff(), null, new \DateTimeImmutable('2026-11-23 08:00:00+00:00'));
        self::assertFalse($order->refund()?->isOverdue(new \DateTimeImmutable('2026-12-01 00:00:00+00:00')), 'a completed refund is never overdue');
    }

    public function testTheHistoryRecordsThePlacementThePaymentAndTheStaffChanges(): void
    {
        $order = self::orderIn('delivered');
        $order->registerReturn(self::staff(), 'Zwrot towaru', new \DateTimeImmutable(self::NOW));
        $staff = StatusActor::staff(self::staff());

        self::assertEquals([
            new OrderStatusChange(null, OrderStatus::New, new \DateTimeImmutable(OrderExamples::PLACED_AT), StatusActor::customer(), null),
            new OrderStatusChange(OrderStatus::New, OrderStatus::Paid, new \DateTimeImmutable(self::PAID_AT), StatusActor::system(), null),
            new OrderStatusChange(OrderStatus::Paid, OrderStatus::Shipped, new \DateTimeImmutable(self::SHIPPED_AT), $staff, null),
            new OrderStatusChange(OrderStatus::Shipped, OrderStatus::Delivered, new \DateTimeImmutable(self::DELIVERED_AT), $staff, null),
            new OrderStatusChange(OrderStatus::Delivered, OrderStatus::Returned, new \DateTimeImmutable(self::NOW), $staff, 'Zwrot towaru'),
        ], $order->history());
        $staffMember = $order->history()[4]->actor()->staffMember();
        self::assertNotNull($staffMember);
        self::assertSame(['kc-staff-1', 'anna'], [$staffMember->staffId(), $staffMember->username()]);
    }

    public function testTheExpiryIsAHistoryEntryOfTheSystem(): void
    {
        $order = self::orderIn('expired');

        self::assertEquals(
            new OrderStatusChange(OrderStatus::New, OrderStatus::Cancelled, new \DateTimeImmutable('2026-11-05 12:35:00+00:00'), StatusActor::system(), null),
            $order->history()[1],
        );
        self::assertCount(2, $order->history());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paidStates(): iterable
    {
        foreach (['shipped', 'delivered', 'returned'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('paidStates')]
    public function testMarkPaidTreatsShippedDeliveredAndReturnedAsPaid(string $state): void
    {
        $order = self::orderIn($state);
        $before = self::snapshot($order);
        $executedAt = new \DateTimeImmutable('2026-11-05 12:20:00+00:00');
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(PaymentResult::AlreadyPaid, $order->markPaid(PaymentId::fromString(self::PAYMENT), OrderExamples::TOTAL, 'PLN', $executedAt, $now));
        self::assertSame(PaymentResult::PaidByAnotherPayment, $order->markPaid(PaymentId::fromString(self::OTHER_PAYMENT), OrderExamples::TOTAL, 'PLN', $executedAt, $now));
        self::assertEquals($before, self::snapshot($order));
        self::assertSame([], $order->releaseEvents());
    }

    public function testMarkPaidOfAnOrderCancelledByStaffIsOrderCancelled(): void
    {
        $order = self::orderIn('cancelled by staff');
        $before = self::snapshot($order);

        self::assertSame(PaymentResult::OrderCancelled, $order->markPaid(PaymentId::fromString(self::PAYMENT), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:20:00+00:00'), new \DateTimeImmutable(self::NOW)));
        self::assertEquals($before, self::snapshot($order));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesAfterThePayment(): iterable
    {
        foreach (['shipped', 'delivered', 'returned', 'cancelled by staff'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('statesAfterThePayment')]
    public function testExpireUnpaidIsNotApplicableAfterThePayment(string $state): void
    {
        $order = self::orderIn($state);
        $before = self::snapshot($order);

        self::assertSame(ExpiryResult::NotApplicable, $order->expireUnpaid(new \DateTimeImmutable(self::NOW)));
        self::assertEquals($before, self::snapshot($order));
        self::assertSame([], $order->releaseEvents());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingRequiredNotes(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
        yield 'only white space' => ['   '];
        yield 'only non-breaking spaces' => ["\u{00A0}\u{00A0}"];
        yield 'only Unicode white space and ASCII spaces' => [" \u{2003}\u{3000}\u{202F} "];
    }

    #[DataProvider('missingRequiredNotes')]
    public function testCancelAndReturnRequireANote(?string $note): void
    {
        foreach ([['paid', StaffTransition::Cancel], ['delivered', StaffTransition::Return]] as [$state, $transition]) {
            $order = self::orderIn($state);
            $before = self::snapshot($order);
            try {
                self::apply($order, $transition, $note, new \DateTimeImmutable(self::NOW));
                self::fail('Expected InvalidOrderInput for '.$transition->value);
            } catch (InvalidOrderInput $invalid) {
                self::assertSame('Notatka jest wymagana.', $invalid->getMessage());
            }
            self::assertEquals($before, self::snapshot($order));
        }
    }

    /**
     * @return iterable<string, array{string, string}> the note and the message
     */
    public static function invalidNotes(): iterable
    {
        yield '501 characters' => [str_repeat('a', 501), 'Notatka może mieć najwyżej 500 znaków.'];
        yield '501 characters after a trim of the ends' => [' '.str_repeat('ż', 501).' ', 'Notatka może mieć najwyżej 500 znaków.'];
        yield 'a line break' => ["Paczka\nwróciła", 'Notatka zawiera niedozwolone znaki.'];
        yield 'a line break at the end' => ["Paczka wróciła\n", 'Notatka zawiera niedozwolone znaki.'];
        yield 'a tab' => ["Paczka\twróciła", 'Notatka zawiera niedozwolone znaki.'];
        yield 'NUL' => ["Paczka\0", 'Notatka zawiera niedozwolone znaki.'];
        yield 'DEL' => ["Paczka\x7F", 'Notatka zawiera niedozwolone znaki.'];
        yield 'next line U+0085' => ["Paczka\u{0085}wróciła", 'Notatka zawiera niedozwolone znaki.'];
        yield 'line separator U+2028' => ["Paczka\u{2028}wróciła", 'Notatka zawiera niedozwolone znaki.'];
        yield 'paragraph separator U+2029' => ["Paczka\u{2029}wróciła", 'Notatka zawiera niedozwolone znaki.'];
        yield 'line separator U+2028 at the end' => ["Paczka wróciła\u{2028}", 'Notatka zawiera niedozwolone znaki.'];
        yield 'only a line separator' => ["\u{2028}", 'Notatka zawiera niedozwolone znaki.'];
        yield 'lone continuation byte' => ["Paczka\x80wróciła", 'Notatka zawiera niedozwolone znaki.'];
        yield 'truncated UTF-8 sequence at the end' => ["Paczka\xC3", 'Notatka zawiera niedozwolone znaki.'];
        yield 'only invalid UTF-8' => ["\xFF", 'Notatka zawiera niedozwolone znaki.'];
        yield '501 characters after a trim of non-breaking spaces' => ["\u{00A0}".str_repeat('ż', 501)."\u{00A0}", 'Notatka może mieć najwyżej 500 znaków.'];
    }

    #[DataProvider('invalidNotes')]
    public function testANoteBreakingTheRulesIsRefusedForEveryChange(string $note, string $message): void
    {
        foreach ([['paid', StaffTransition::Ship], ['paid', StaffTransition::Cancel], ['shipped', StaffTransition::Deliver], ['shipped', StaffTransition::Return]] as [$state, $transition]) {
            $order = self::orderIn($state);
            $before = self::snapshot($order);
            try {
                self::apply($order, $transition, $note, new \DateTimeImmutable(self::NOW));
                self::fail('Expected InvalidOrderInput for '.$transition->value);
            } catch (InvalidOrderInput $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
            self::assertEquals($before, self::snapshot($order));
        }
        $refunded = self::orderIn('returned');
        try {
            $refunded->completeRefund(self::staff(), $note, new \DateTimeImmutable(self::NOW));
            self::fail('Expected InvalidOrderInput for the completion of the refund.');
        } catch (InvalidOrderInput) {
            self::assertTrue($refunded->refund()?->isPending());
        }
    }

    public function testANoteIsTrimmedOfUnicodeWhiteSpace(): void
    {
        $order = self::orderIn('paid');

        $order->cancel(self::staff(), "\u{00A0} ".str_repeat('ż', 500)."\u{3000}", new \DateTimeImmutable(self::NOW));

        self::assertSame(str_repeat('ż', 500), self::lastChange($order)->note());
    }

    public function testANoteOfFiveHundredCharactersIsKept(): void
    {
        $order = self::orderIn('paid');
        $note = str_repeat('ż', 500);

        $order->cancel(self::staff(), $note, new \DateTimeImmutable(self::NOW));

        self::assertSame($note, self::lastChange($order)->note());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function emptyOptionalNotes(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
        yield 'only spaces' => ['    '];
        yield 'only non-breaking spaces' => ["\u{00A0}\u{00A0}"];
    }

    #[DataProvider('emptyOptionalNotes')]
    public function testAnEmptyNoteOfShipAndDeliverIsNoNote(?string $note): void
    {
        $order = self::orderIn('paid');

        $order->ship(self::staff(), $note, new \DateTimeImmutable(self::SHIPPED_AT));
        $order->deliver(self::staff(), $note, new \DateTimeImmutable(self::DELIVERED_AT));

        $history = $order->history();
        self::assertNull($history[2]->note());
        self::assertNull($history[3]->note());
    }

    public function testAStaffActorNeedsAnIdAndAName(): void
    {
        foreach ([['', 'anna'], ['kc-staff-1', ''], ['kc-staff-1', '  '], [str_repeat('a', 256), 'anna'], ['kc-staff-1', str_repeat('ż', 256)]] as [$id, $name]) {
            try {
                StaffActor::of($id, $name);
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(['kc-staff-1', str_repeat('ż', 255)], [StaffActor::of('kc-staff-1', str_repeat('ż', 255))->staffId(), StaffActor::of('kc-staff-1', str_repeat('ż', 255))->username()]);
    }

    public function testReconstituteKeepsTheLifecycleState(): void
    {
        $order = self::orderIn('returned');
        $order->completeRefund(StaffActor::of('kc-staff-2', 'bartek'), 'Przelew', new \DateTimeImmutable('2026-11-09 08:00:00+00:00'));

        $copy = OrderExamples::copy($order, 7);

        self::assertEquals([...self::snapshot($order), 'version' => 7], self::snapshot($copy));
        self::assertSame([], $copy->releaseEvents());
    }

    private static function lastChange(Order $order): OrderStatusChange
    {
        $history = $order->history();
        self::assertNotSame([], $history);

        return $history[\count($history) - 1];
    }

    private static function staff(): StaffActor
    {
        return StaffActor::of('kc-staff-1', 'anna');
    }

    /**
     * The example order brought to a state through the domain: new, paid, shipped, delivered, expired (cancelled
     * unpaid), cancelled by staff, returned (from delivered). The events are released.
     */
    private static function orderIn(string $state): Order
    {
        $order = OrderExamples::placed();
        if ('expired' === $state) {
            $order->expireUnpaid(new \DateTimeImmutable('2026-11-05 12:35:00+00:00'));
            $order->releaseEvents();

            return $order;
        }
        if ('new' !== $state) {
            $order->markPaid(PaymentId::fromString(self::PAYMENT), OrderExamples::TOTAL, 'PLN', new \DateTimeImmutable('2026-11-05 12:10:00+00:00'), new \DateTimeImmutable(self::PAID_AT));
        }
        if ('cancelled by staff' === $state) {
            $order->cancel(self::staff(), 'Klient zrezygnował', new \DateTimeImmutable(self::NOW));
        }
        if (\in_array($state, ['shipped', 'delivered', 'returned'], true)) {
            $order->ship(self::staff(), null, new \DateTimeImmutable(self::SHIPPED_AT));
        }
        if (\in_array($state, ['delivered', 'returned'], true)) {
            $order->deliver(self::staff(), null, new \DateTimeImmutable(self::DELIVERED_AT));
        }
        if ('returned' === $state) {
            $order->registerReturn(self::staff(), 'Zwrot towaru', new \DateTimeImmutable(self::NOW));
        }
        $order->releaseEvents();

        return $order;
    }

    private static function apply(Order $order, StaffTransition $transition, ?string $note, \DateTimeImmutable $now): void
    {
        match ($transition) {
            StaffTransition::Ship => $order->ship(self::staff(), $note, $now),
            StaffTransition::Deliver => $order->deliver(self::staff(), $note, $now),
            StaffTransition::Cancel => $order->cancel(self::staff(), $note, $now),
            StaffTransition::Return => $order->registerReturn(self::staff(), $note, $now),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Order $order): array
    {
        return [
            'status' => $order->status(),
            'paidAt' => $order->paidAt(),
            'paymentId' => $order->paymentId()?->toString(),
            'shippedAt' => $order->shippedAt(),
            'deliveredAt' => $order->deliveredAt(),
            'returnedAt' => $order->returnedAt(),
            'cancelledAt' => $order->cancelledAt(),
            'cancellationReason' => $order->cancellationReason(),
            'refund' => $order->refund(),
            'history' => $order->history(),
            'version' => $order->version(),
        ];
    }
}

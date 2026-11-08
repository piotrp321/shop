<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * An order placed from a cart: copies of the lines (names, labels, unit prices), the contact and the shipping
 * address as they were at the moment of placement, never references. The totals are computed from the lines.
 *
 * The Idempotency-Key and the fingerprint of the request tell a repeated placement (isReplayOf()) from another one.
 * The version is the stored version the state is based on (optimistic locking): paying and cancelling an order
 * concurrently ends with one of them, the other is refused when it saves.
 *
 * A new order is to be paid by its deadline, PAYMENT_TIME after the placement. Whether a payment pays it is decided
 * by the moment the provider executed it, not by when the news arrives: the webhooks get DELIVERY_WINDOW after the
 * deadline, and only then is an order still unpaid cancelled. A cancelled order is never paid. A guest reaches the
 * order by its access token, a customer by the id.
 *
 * Staff take a paid order on (ship, deliver, cancel, registerReturn) only as the table of OrderStatus allows. A
 * cancellation by staff and a return create the refund of the gross total, due REFUND_TIME later, which staff then
 * mark completed. Every change of the status appends an entry to the history, which is never rewritten.
 */
final class Order
{
    public const int MAX_LINES = 50;
    public const string PAYMENT_TIME = '+30 minutes';
    public const string DELIVERY_WINDOW = '+5 minutes';
    public const string REFUND_TIME = '+14 days';

    /** @var list<DomainEvent> */
    private array $events = [];

    /**
     * @param list<OrderLine>         $lines
     * @param list<OrderStatusChange> $history
     */
    private function __construct(
        private readonly OrderId $id,
        private readonly OrderNumber $number,
        private readonly OrderOwner $owner,
        private readonly ?AccessToken $accessToken,
        private readonly Contact $contact,
        private readonly ShippingAddress $shippingAddress,
        private readonly array $lines,
        private readonly OrderTotals $totals,
        private OrderStatus $status,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly RequestFingerprint $requestFingerprint,
        private readonly \DateTimeImmutable $placedAt,
        private readonly \DateTimeImmutable $paymentDeadline,
        private ?\DateTimeImmutable $paidAt,
        private ?PaymentId $paymentId,
        private ?\DateTimeImmutable $cancelledAt,
        private ?CancellationReason $cancellationReason,
        private ?\DateTimeImmutable $shippedAt,
        private ?\DateTimeImmutable $deliveredAt,
        private ?\DateTimeImmutable $returnedAt,
        private ?Refund $refund,
        private array $history,
        private readonly int $version,
    ) {
    }

    /**
     * A new order in the status new, to be paid by PAYMENT_TIME from now; records OrderPlaced.
     *
     * @param ?AccessToken    $accessToken of a guest order; a customer order has none
     * @param list<OrderLine> $lines       in the order of the cart
     *
     * @throws InvalidOrder no line, more than MAX_LINES, a variant twice, a guest order without a token or
     *                      a customer order with one
     */
    public static function place(
        OrderId $id,
        OrderNumber $number,
        OrderOwner $owner,
        ?AccessToken $accessToken,
        Contact $contact,
        ShippingAddress $shippingAddress,
        array $lines,
        IdempotencyKey $idempotencyKey,
        RequestFingerprint $requestFingerprint,
        \DateTimeImmutable $now,
    ): self {
        self::assertLines($lines);
        if ($owner->isGuest() !== (null !== $accessToken)) {
            throw new InvalidOrder('A guest order has an access token, a customer order has none.');
        }
        $history = [new OrderStatusChange(null, OrderStatus::New, $now, StatusActor::customer(), null)];
        $order = new self($id, $number, $owner, $accessToken, $contact, $shippingAddress, $lines, OrderTotals::of($lines), OrderStatus::New, $idempotencyKey, $requestFingerprint, $now, $now->modify(self::PAYMENT_TIME), null, null, null, null, null, null, null, null, $history, 1);
        $order->events[] = new OrderPlaced($id->toString(), $now);

        return $order;
    }

    /**
     * The order as stored, without events.
     *
     * @param list<OrderLine>         $lines   in their positions
     * @param list<OrderStatusChange> $history oldest first
     */
    public static function reconstitute(
        OrderId $id,
        OrderNumber $number,
        OrderOwner $owner,
        ?AccessToken $accessToken,
        Contact $contact,
        ShippingAddress $shippingAddress,
        array $lines,
        OrderStatus $status,
        IdempotencyKey $idempotencyKey,
        RequestFingerprint $requestFingerprint,
        \DateTimeImmutable $placedAt,
        \DateTimeImmutable $paymentDeadline,
        ?\DateTimeImmutable $paidAt,
        ?PaymentId $paymentId,
        ?\DateTimeImmutable $cancelledAt,
        ?CancellationReason $cancellationReason,
        ?\DateTimeImmutable $shippedAt,
        ?\DateTimeImmutable $deliveredAt,
        ?\DateTimeImmutable $returnedAt,
        ?Refund $refund,
        array $history,
        int $version,
    ): self {
        return new self($id, $number, $owner, $accessToken, $contact, $shippingAddress, $lines, OrderTotals::of($lines), $status, $idempotencyKey, $requestFingerprint, $placedAt, $paymentDeadline, $paidAt, $paymentId, $cancelledAt, $cancellationReason, $shippedAt, $deliveredAt, $returnedAt, $refund, $history, $version);
    }

    /**
     * Whether a request with this fingerprint is this placement repeated with the same key: the same cart and the
     * same content.
     */
    public function isReplayOf(RequestFingerprint $fingerprint): bool
    {
        return $this->requestFingerprint->equals($fingerprint);
    }

    /**
     * Whether the claim reaches this order: a guest by the token of a guest order (compared in constant time),
     * a customer by the id of a customer order. Any other combination is refused.
     */
    public function grantsAccess(AccessClaim $claim): bool
    {
        $token = $claim->token();
        if (null !== $token) {
            return null !== $this->accessToken && $this->accessToken->matches($token);
        }

        return !$this->owner->isGuest() && $this->owner->customerId() === $claim->customerId();
    }

    /**
     * Whether a payment can be started now: the order is new and its deadline has not come.
     */
    public function isPayable(\DateTimeImmutable $now): bool
    {
        return OrderStatus::New === $this->status && $now < $this->paymentDeadline;
    }

    /**
     * A payment of the order succeeded at the provider at $executedAt (the moment the provider signed), and the news
     * of it arrived $now. A new order is paid if the payment was executed by the deadline (inclusive); one executed
     * later leaves it new. An order paid before (also shipped, delivered or returned) or cancelled stays as it is.
     * The amount is checked only when it pays the order: the payment takes it from the order, so another one is
     * a logic error.
     *
     * @throws InvalidOrder the amount or the currency is not the gross total of the order
     */
    public function markPaid(PaymentId $paymentId, int $amount, string $currency, \DateTimeImmutable $executedAt, \DateTimeImmutable $now): PaymentResult
    {
        if (OrderStatus::Cancelled === $this->status) {
            return PaymentResult::OrderCancelled;
        }
        if ($this->status->isPaidFor()) {
            return null !== $this->paymentId && $this->paymentId->equals($paymentId) ? PaymentResult::AlreadyPaid : PaymentResult::PaidByAnotherPayment;
        }
        if ($executedAt > $this->paymentDeadline) {
            return PaymentResult::PaidAfterDeadline;
        }
        $gross = $this->totals->gross();
        if ($amount !== $gross->amount() || $currency !== $gross->currency()->value) {
            throw new InvalidOrder('A payment pays the gross total of the order in its currency.');
        }

        $this->changeStatus(OrderStatus::Paid, $now, StatusActor::system(), null);
        $this->paidAt = $now;
        $this->paymentId = $paymentId;
        $this->events[] = new OrderPaid($this->id->toString(), $now);

        return PaymentResult::Paid;
    }

    /**
     * Cancels a new order still unpaid once the deadline and the window for the webhooks after it have passed
     * (expiryDueAt()); records OrderCancelled. Any order but a new one is left as it is.
     */
    public function expireUnpaid(\DateTimeImmutable $now): ExpiryResult
    {
        if (OrderStatus::New !== $this->status) {
            return ExpiryResult::NotApplicable;
        }
        if ($now < $this->expiryDueAt()) {
            return ExpiryResult::TooEarly;
        }

        $this->changeStatus(OrderStatus::Cancelled, $now, StatusActor::system(), null);
        $this->cancelledAt = $now;
        $this->cancellationReason = CancellationReason::PaymentTimeout;
        $this->events[] = new OrderCancelled($this->id->toString(), CancellationReason::PaymentTimeout->value, $now);

        return ExpiryResult::Cancelled;
    }

    /**
     * A staff member shipped the paid order; records OrderShipped. The caller commits its reservation.
     *
     * @throws InvalidOrderInput         the note breaks the rules of StaffNote
     * @throws OrderTransitionNotAllowed
     */
    public function ship(StaffActor $staff, ?string $note, \DateTimeImmutable $now): void
    {
        $note = StaffNote::optional($note);
        $this->assertAllowed(StaffTransition::Ship);

        $this->changeStatus(OrderStatus::Shipped, $now, StatusActor::staff($staff), $note);
        $this->shippedAt = $now;
        $this->events[] = new OrderShipped($this->id->toString(), $now);
    }

    /**
     * A staff member marked the shipped order delivered. No event: the customer gets no email about it.
     *
     * @throws InvalidOrderInput         the note breaks the rules of StaffNote
     * @throws OrderTransitionNotAllowed
     */
    public function deliver(StaffActor $staff, ?string $note, \DateTimeImmutable $now): void
    {
        $note = StaffNote::optional($note);
        $this->assertAllowed(StaffTransition::Deliver);

        $this->changeStatus(OrderStatus::Delivered, $now, StatusActor::staff($staff), $note);
        $this->deliveredAt = $now;
    }

    /**
     * A staff member cancelled the paid order before shipping it, with a note; the payment stays recorded and its
     * refund is due. Records OrderCancelled. The caller releases its reservation.
     *
     * @throws InvalidOrderInput         no note, or one breaking the rules of StaffNote
     * @throws OrderTransitionNotAllowed
     */
    public function cancel(StaffActor $staff, ?string $note, \DateTimeImmutable $now): void
    {
        $note = StaffNote::required($note);
        $this->assertAllowed(StaffTransition::Cancel);

        $this->changeStatus(OrderStatus::Cancelled, $now, StatusActor::staff($staff), $note);
        $this->cancelledAt = $now;
        $this->cancellationReason = CancellationReason::Staff;
        $this->refund = Refund::due($this->totals->gross(), $now->modify(self::REFUND_TIME));
        $this->events[] = new OrderCancelled($this->id->toString(), CancellationReason::Staff->value, $now);
    }

    /**
     * A staff member received the shipped (undelivered, came back) or delivered order back, with a note; its refund
     * is due. Records OrderReturned. The goods do not go back on the stock by themselves.
     *
     * @throws InvalidOrderInput         no note, or one breaking the rules of StaffNote
     * @throws OrderTransitionNotAllowed
     */
    public function registerReturn(StaffActor $staff, ?string $note, \DateTimeImmutable $now): void
    {
        $note = StaffNote::required($note);
        $this->assertAllowed(StaffTransition::Return);

        $this->changeStatus(OrderStatus::Returned, $now, StatusActor::staff($staff), $note);
        $this->returnedAt = $now;
        $this->refund = Refund::due($this->totals->gross(), $now->modify(self::REFUND_TIME));
        $this->events[] = new OrderReturned($this->id->toString(), $now);
    }

    /**
     * A staff member transferred the pending refund (outside the system). The status and the history stay.
     *
     * @throws InvalidOrderInput the note breaks the rules of StaffNote
     * @throws RefundNotPending  no refund, or it is completed already
     */
    public function completeRefund(StaffActor $staff, ?string $note, \DateTimeImmutable $now): void
    {
        $note = StaffNote::optional($note);
        if (null === $this->refund) {
            throw new RefundNotPending();
        }

        $this->refund = $this->refund->complete($staff, $note, $now);
    }

    /**
     * From when an unpaid order is cancelled: the deadline and the window for the webhooks after it.
     */
    public function expiryDueAt(): \DateTimeImmutable
    {
        return $this->paymentDeadline->modify(self::DELIVERY_WINDOW);
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function number(): OrderNumber
    {
        return $this->number;
    }

    public function owner(): OrderOwner
    {
        return $this->owner;
    }

    public function accessToken(): ?AccessToken
    {
        return $this->accessToken;
    }

    public function contact(): Contact
    {
        return $this->contact;
    }

    public function shippingAddress(): ShippingAddress
    {
        return $this->shippingAddress;
    }

    /**
     * @return list<OrderLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totals(): OrderTotals
    {
        return $this->totals;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function requestFingerprint(): RequestFingerprint
    {
        return $this->requestFingerprint;
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function paymentDeadline(): \DateTimeImmutable
    {
        return $this->paymentDeadline;
    }

    public function paidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function paymentId(): ?PaymentId
    {
        return $this->paymentId;
    }

    public function cancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancellationReason(): ?CancellationReason
    {
        return $this->cancellationReason;
    }

    public function shippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function deliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function returnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function refund(): ?Refund
    {
        return $this->refund;
    }

    /**
     * @return list<OrderStatusChange> oldest first
     */
    public function history(): array
    {
        return $this->history;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @throws OrderTransitionNotAllowed
     */
    private function assertAllowed(StaffTransition $transition): void
    {
        if (!$this->status->allowsStaff($transition)) {
            throw new OrderTransitionNotAllowed();
        }
    }

    private function changeStatus(OrderStatus $to, \DateTimeImmutable $now, StatusActor $actor, ?string $note): void
    {
        $this->history[] = new OrderStatusChange($this->status, $to, $now, $actor, $note);
        $this->status = $to;
    }

    /**
     * @param list<OrderLine> $lines
     *
     * @throws InvalidOrder
     */
    private static function assertLines(array $lines): void
    {
        $count = \count($lines);
        if ($count < 1 || $count > self::MAX_LINES) {
            throw new InvalidOrder(\sprintf('An order has 1 to %d lines.', self::MAX_LINES));
        }
        $variants = [];
        foreach ($lines as $line) {
            $key = $line->variantId()->toString();
            if (isset($variants[$key])) {
                throw new InvalidOrder('A variant is in one line of an order only.');
            }
            $variants[$key] = true;
        }
    }
}

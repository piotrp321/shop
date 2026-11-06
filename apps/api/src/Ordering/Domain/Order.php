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
 * deadline, and only then is an order still unpaid cancelled. A paid order is never cancelled and a cancelled one is
 * never paid. A guest reaches the order by its access token, a customer by the id.
 */
final class Order
{
    public const int MAX_LINES = 50;
    public const string PAYMENT_TIME = '+30 minutes';
    public const string DELIVERY_WINDOW = '+5 minutes';

    /** @var list<DomainEvent> */
    private array $events = [];

    /**
     * @param list<OrderLine> $lines
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
        private ?\DateTimeImmutable $confirmationSentAt,
        private ?\DateTimeImmutable $paymentNotificationSentAt,
        private ?\DateTimeImmutable $cancellationNotificationSentAt,
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
        $order = new self($id, $number, $owner, $accessToken, $contact, $shippingAddress, $lines, OrderTotals::of($lines), OrderStatus::New, $idempotencyKey, $requestFingerprint, $now, $now->modify(self::PAYMENT_TIME), null, null, null, null, null, null, null, 1);
        $order->events[] = new OrderPlaced($id->toString(), $now);

        return $order;
    }

    /**
     * The order as stored, without events.
     *
     * @param list<OrderLine> $lines in their positions
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
        ?\DateTimeImmutable $confirmationSentAt,
        ?\DateTimeImmutable $paymentNotificationSentAt,
        ?\DateTimeImmutable $cancellationNotificationSentAt,
        int $version,
    ): self {
        return new self($id, $number, $owner, $accessToken, $contact, $shippingAddress, $lines, OrderTotals::of($lines), $status, $idempotencyKey, $requestFingerprint, $placedAt, $paymentDeadline, $paidAt, $paymentId, $cancelledAt, $cancellationReason, $confirmationSentAt, $paymentNotificationSentAt, $cancellationNotificationSentAt, $version);
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
     * later leaves it new. A paid or cancelled order stays as it is. The amount is checked only when it pays the
     * order: the payment takes it from the order, so another one is a logic error.
     *
     * @throws InvalidOrder the amount or the currency is not the gross total of the order
     */
    public function markPaid(PaymentId $paymentId, int $amount, string $currency, \DateTimeImmutable $executedAt, \DateTimeImmutable $now): PaymentResult
    {
        if (OrderStatus::Cancelled === $this->status) {
            return PaymentResult::OrderCancelled;
        }
        if (OrderStatus::Paid === $this->status) {
            return null !== $this->paymentId && $this->paymentId->equals($paymentId) ? PaymentResult::AlreadyPaid : PaymentResult::PaidByAnotherPayment;
        }
        if ($executedAt > $this->paymentDeadline) {
            return PaymentResult::PaidAfterDeadline;
        }
        $gross = $this->totals->gross();
        if ($amount !== $gross->amount() || $currency !== $gross->currency()->value) {
            throw new InvalidOrder('A payment pays the gross total of the order in its currency.');
        }

        $this->status = OrderStatus::Paid;
        $this->paidAt = $now;
        $this->paymentId = $paymentId;
        $this->events[] = new OrderPaid($this->id->toString(), $now);

        return PaymentResult::Paid;
    }

    /**
     * Cancels a new order still unpaid once the deadline and the window for the webhooks after it have passed
     * (expiryDueAt()); records OrderCancelled. A paid or cancelled order is left as it is.
     */
    public function expireUnpaid(\DateTimeImmutable $now): ExpiryResult
    {
        if (OrderStatus::New !== $this->status) {
            return ExpiryResult::NotApplicable;
        }
        if ($now < $this->expiryDueAt()) {
            return ExpiryResult::TooEarly;
        }

        $this->status = OrderStatus::Cancelled;
        $this->cancelledAt = $now;
        $this->cancellationReason = CancellationReason::PaymentTimeout;
        $this->events[] = new OrderCancelled($this->id->toString(), CancellationReason::PaymentTimeout->value, $now);

        return ExpiryResult::Cancelled;
    }

    /**
     * From when an unpaid order is cancelled: the deadline and the window for the webhooks after it.
     */
    public function expiryDueAt(): \DateTimeImmutable
    {
        return $this->paymentDeadline->modify(self::DELIVERY_WINDOW);
    }

    /**
     * The confirmation email went out; a repetition keeps the first moment.
     */
    public function markConfirmationSent(\DateTimeImmutable $now): void
    {
        $this->confirmationSentAt ??= $now;
    }

    /**
     * The email about the payment went out; a repetition keeps the first moment.
     */
    public function markPaymentNotificationSent(\DateTimeImmutable $now): void
    {
        $this->paymentNotificationSentAt ??= $now;
    }

    /**
     * The email about the cancellation went out; a repetition keeps the first moment.
     */
    public function markCancellationNotificationSent(\DateTimeImmutable $now): void
    {
        $this->cancellationNotificationSentAt ??= $now;
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

    public function confirmationSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationSentAt;
    }

    public function paymentNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->paymentNotificationSentAt;
    }

    public function cancellationNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->cancellationNotificationSentAt;
    }

    public function version(): int
    {
        return $this->version;
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

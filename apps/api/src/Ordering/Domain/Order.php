<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Event\DomainEvent;

/**
 * An order placed from a cart: copies of the lines (names, labels, unit prices), the contact and the shipping
 * address as they were at the moment of placement, never references. The totals are computed from the lines.
 *
 * The Idempotency-Key and the fingerprint of the request tell a repeated placement (isReplayOf()) from another one.
 * The version is the stored version the state is based on (optimistic locking).
 */
final class Order
{
    public const int MAX_LINES = 50;

    /** @var list<DomainEvent> */
    private array $events = [];

    /**
     * @param list<OrderLine> $lines
     */
    private function __construct(
        private readonly OrderId $id,
        private readonly OrderNumber $number,
        private readonly OrderOwner $owner,
        private readonly Contact $contact,
        private readonly ShippingAddress $shippingAddress,
        private readonly array $lines,
        private readonly OrderTotals $totals,
        private readonly OrderStatus $status,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly RequestFingerprint $requestFingerprint,
        private readonly \DateTimeImmutable $placedAt,
        private ?\DateTimeImmutable $confirmationSentAt,
        private readonly int $version,
    ) {
    }

    /**
     * A new order in the status new; records OrderPlaced.
     *
     * @param list<OrderLine> $lines in the order of the cart
     *
     * @throws InvalidOrder no line, more than MAX_LINES, a variant twice
     */
    public static function place(
        OrderId $id,
        OrderNumber $number,
        OrderOwner $owner,
        Contact $contact,
        ShippingAddress $shippingAddress,
        array $lines,
        IdempotencyKey $idempotencyKey,
        RequestFingerprint $requestFingerprint,
        \DateTimeImmutable $now,
    ): self {
        self::assertLines($lines);
        $order = new self($id, $number, $owner, $contact, $shippingAddress, $lines, OrderTotals::of($lines), OrderStatus::New, $idempotencyKey, $requestFingerprint, $now, null, 1);
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
        Contact $contact,
        ShippingAddress $shippingAddress,
        array $lines,
        OrderStatus $status,
        IdempotencyKey $idempotencyKey,
        RequestFingerprint $requestFingerprint,
        \DateTimeImmutable $placedAt,
        ?\DateTimeImmutable $confirmationSentAt,
        int $version,
    ): self {
        return new self($id, $number, $owner, $contact, $shippingAddress, $lines, OrderTotals::of($lines), $status, $idempotencyKey, $requestFingerprint, $placedAt, $confirmationSentAt, $version);
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
     * The confirmation email went out; a repetition keeps the first moment.
     */
    public function markConfirmationSent(\DateTimeImmutable $now): void
    {
        $this->confirmationSentAt ??= $now;
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

    public function confirmationSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationSentAt;
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

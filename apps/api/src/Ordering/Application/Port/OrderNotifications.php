<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderId;

/**
 * Which emails of an order went out, outside the Order aggregate: sending one never changes the order or its version.
 * A sender claims the sending of one kind for LEASE before it sends, so of concurrent senders only one sends; it marks
 * the email sent afterwards, or releases the claim when the sending failed. A sender that dies between sending and
 * marking leaves the claim to expire: the email goes out a second time after the lease (at least once). Every call
 * takes effect at once, on its own, outside any transaction of the caller.
 */
interface OrderNotifications
{
    /** How long a claim holds the sending; longer than the longest sending of an email can take. */
    public const string LEASE = '+5 minutes';

    /**
     * Claims the sending until $now + LEASE when the email has not gone out and nobody holds an unexpired claim
     * (one that ends at $now has expired).
     */
    public function claim(OrderId $orderId, NotificationKind $kind, \DateTimeImmutable $now): NotificationClaim;

    /**
     * The email went out at $now; the claim ends. A repetition keeps the first moment.
     */
    public function markSent(OrderId $orderId, NotificationKind $kind, \DateTimeImmutable $now): void;

    /**
     * Gives the claim up, so the next claim gets the sending at once; a sent email stays sent.
     */
    public function release(OrderId $orderId, NotificationKind $kind): void;
}

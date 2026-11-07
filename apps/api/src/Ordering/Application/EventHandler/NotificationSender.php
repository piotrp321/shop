<?php

declare(strict_types=1);

namespace App\Ordering\Application\EventHandler;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Application\Port\OrderNotifications;
use App\Ordering\Domain\OrderId;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Sends an email of an order at most once at a time and never again once it went out, for the handlers of the events
 * (outside a transaction; every step of OrderNotifications takes effect at once): claim the sending, send, mark it
 * sent. The order and its version stay as they are.
 *
 * A sending another handler holds is retried one second after the end of its lease, however many times that takes
 * (a held claim is no failure of the message): by then the holder has marked the email sent, released the claim or
 * died and let the lease expire. A failed sending releases the claim and the failure goes on to the retries of the
 * transport. A handler that dies between sending and marking makes the next one send again after the lease.
 */
final readonly class NotificationSender
{
    public function __construct(
        private OrderNotifications $notifications,
        private Clock $clock,
    ) {
    }

    /**
     * @param \Closure(): void $send sends the email; a failure of the mail transport is thrown
     *
     * @throws RecoverableMessageHandlingException another handler holds the sending; retried after its lease
     * @throws \Throwable                          what $send threw; the claim is released
     */
    public function sendOnce(OrderId $orderId, NotificationKind $kind, \Closure $send): void
    {
        $now = $this->clock->now();
        $claim = $this->notifications->claim($orderId, $kind, $now);
        if ($claim->isAlreadySent()) {
            return;
        }
        $heldUntil = $claim->heldUntil();
        if (null !== $heldUntil) {
            throw new RecoverableMessageHandlingException(\sprintf('The %s email of order "%s" is being sent by another handler until %s.', $kind->value, $orderId->toString(), $heldUntil->format(\DATE_ATOM)), retryDelay: self::delayUntilAfter($heldUntil, $now));
        }

        try {
            $send();
        } catch (\Throwable $failure) {
            $this->notifications->release($orderId, $kind);

            throw $failure;
        }
        $this->notifications->markSent($orderId, $kind, $this->clock->now());
    }

    /**
     * Milliseconds from $now to one second after $until.
     */
    private static function delayUntilAfter(\DateTimeImmutable $until, \DateTimeImmutable $now): int
    {
        $microseconds = ((int) $until->format('U') - (int) $now->format('U') + 1) * 1_000_000
            + (int) $until->format('u') - (int) $now->format('u');

        return max(0, intdiv($microseconds + 999, 1000));
    }
}

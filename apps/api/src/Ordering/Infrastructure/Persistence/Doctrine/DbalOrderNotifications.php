<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\NotificationClaim;
use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Application\Port\OrderNotifications;
use App\Ordering\Domain\OrderId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * ordering_order_notification through DBAL, not through records of the ORM (OrderNotificationRecord only defines the
 * schema), and never through the order row, so its version stays. Every call is one statement that commits on its
 * own, as the handlers run outside a transaction.
 *
 * The claim is one conditional upsert: a concurrent first claim of the same notification waits for the row of the
 * other and then finds it held, instead of a unique violation (which would close the EntityManager shared with the
 * handler). A claim that did not take the row reads why; a row released or expired in between is claimed again.
 * The times have the precision of the columns (whole seconds, the fraction cut off).
 */
final readonly class DbalOrderNotifications implements OrderNotifications
{
    private const int CLAIM_ATTEMPTS = 3;

    public function __construct(private Connection $connection)
    {
    }

    public function claim(OrderId $orderId, NotificationKind $kind, \DateTimeImmutable $now): NotificationClaim
    {
        for ($attempt = 1; $attempt <= self::CLAIM_ATTEMPTS; ++$attempt) {
            $taken = $this->connection->fetchOne(
                'INSERT INTO ordering_order_notification AS n (order_id, kind, claimed_until, sent_at)
                 VALUES (:order, :kind, :until, NULL)
                 ON CONFLICT (order_id, kind) DO UPDATE SET claimed_until = EXCLUDED.claimed_until
                 WHERE n.sent_at IS NULL AND (n.claimed_until IS NULL OR n.claimed_until <= :now)
                 RETURNING 1',
                ['order' => $orderId->toString(), 'kind' => $kind->value, 'until' => $now->modify(self::LEASE), 'now' => $now],
                ['until' => Types::DATETIMETZ_IMMUTABLE, 'now' => Types::DATETIMETZ_IMMUTABLE],
            );
            if (false !== $taken) {
                return NotificationClaim::claimed();
            }

            $row = $this->connection->fetchAssociative(
                'SELECT claimed_until, sent_at FROM ordering_order_notification WHERE order_id = :order AND kind = :kind',
                ['order' => $orderId->toString(), 'kind' => $kind->value],
            );
            if (false === $row) {
                continue;
            }
            if (null !== $row['sent_at']) {
                return NotificationClaim::alreadySent();
            }
            $until = $this->toDateTime($row['claimed_until']);
            if (null !== $until && $until > $now) {
                return NotificationClaim::inProgress($until);
            }
            // Released or expired between the two statements: try again.
        }

        // Others keep claiming and releasing it: come back in a moment.
        return NotificationClaim::inProgress($now);
    }

    public function markSent(OrderId $orderId, NotificationKind $kind, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ordering_order_notification AS n (order_id, kind, claimed_until, sent_at)
             VALUES (:order, :kind, NULL, :now)
             ON CONFLICT (order_id, kind) DO UPDATE SET sent_at = COALESCE(n.sent_at, EXCLUDED.sent_at), claimed_until = NULL',
            ['order' => $orderId->toString(), 'kind' => $kind->value, 'now' => $now],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function release(OrderId $orderId, NotificationKind $kind): void
    {
        $this->connection->executeStatement(
            'UPDATE ordering_order_notification SET claimed_until = NULL WHERE order_id = :order AND kind = :kind AND sent_at IS NULL',
            ['order' => $orderId->toString(), 'kind' => $kind->value],
        );
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        $converted = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($value, $this->connection->getDatabasePlatform());
        \assert(null === $converted || $converted instanceof \DateTimeImmutable);

        return $converted;
    }
}

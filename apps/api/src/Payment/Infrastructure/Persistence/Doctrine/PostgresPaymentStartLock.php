<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence\Doctrine;

use App\Payment\Application\Port\PaymentStartLock;
use App\Payment\Domain\OrderId;
use Doctrine\DBAL\Connection;

/**
 * A transaction-level PostgreSQL advisory lock on the order, taken without waiting (pg_try_advisory_xact_lock): held
 * until the transaction of the start ends, also when the process dies. The one statement of Payment outside its
 * records that only locks; the data goes through them. The transaction that holds the lock takes it again.
 *
 * The two-key form (int, int): the first key is fixed for the payment starts, the second a hash of the order id, so
 * two orders with the same hash share the lock (a 409 for one of them; a retry goes through).
 */
final readonly class PostgresPaymentStartLock implements PaymentStartLock
{
    /**
     * The first key of the two-key advisory locks of the payment starts, chosen for them (Inventory: "ST", the
     * barriers of the concurrency tests: 0x1A7E, 0x1A7F); the one-key locks never meet the two-key ones.
     */
    public const int LOCK_NAMESPACE = 0x5041; // "PA"

    public function __construct(private Connection $connection)
    {
    }

    public function tryAcquire(OrderId $orderId): bool
    {
        // Outside a transaction the lock would end with its own statement and guard nothing.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The lock of the payment starts needs the transaction of the start.');
        }

        return true === $this->connection->fetchOne(
            'SELECT pg_try_advisory_xact_lock(CAST(? AS int), CAST(? AS int))',
            [self::LOCK_NAMESPACE, self::lockKey($orderId)],
        );
    }

    private static function lockKey(OrderId $orderId): int
    {
        $hash = crc32($orderId->toString());

        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }
}

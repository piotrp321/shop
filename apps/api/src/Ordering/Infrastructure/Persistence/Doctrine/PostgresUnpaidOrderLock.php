<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\UnpaidOrderLock;
use App\Ordering\Domain\UnpaidOrderIdentity;
use Doctrine\DBAL\Connection;

/**
 * Transaction-level PostgreSQL advisory locks of the limit of unpaid orders (pg_advisory_xact_lock): one on the
 * address, then one on the identity, held until the transaction of the placement ends, also when the process dies.
 * The one statement of the limit outside its record, which only locks; the claims are counted and written through
 * DoctrineUnpaidOrderClaims.
 *
 * The two-key form (int, int): the first key is fixed, one for the addresses and one for the identities, the second a
 * hash of the key. Every placement takes one lock of each namespace, addresses first, so the locks form no cycle; two
 * keys with the same hash share the lock, which only makes them wait for each other.
 */
final readonly class PostgresUnpaidOrderLock implements UnpaidOrderLock
{
    /**
     * The first keys of the two-key advisory locks of the limit, chosen for it (Payment: 0x5041, Inventory: "ST",
     * the barriers of the concurrency tests: 0x1A7E, 0x1A7F); the one-key locks never meet the two-key ones.
     */
    public const int ADDRESS_NAMESPACE = 0x4F41; // "OA"
    public const int IDENTITY_NAMESPACE = 0x4F49; // "OI"

    public function __construct(private Connection $connection)
    {
    }

    public function lock(string $address, UnpaidOrderIdentity $identity): void
    {
        // Outside a transaction the lock would end with its own statement and guard nothing.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The locks of the limit of unpaid orders need the transaction of the placement.');
        }
        // Two statements, so the address is locked before the identity.
        $this->take(self::ADDRESS_NAMESPACE, $address);
        $this->take(self::IDENTITY_NAMESPACE, $identity->key());
    }

    private function take(int $namespace, string $key): void
    {
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(CAST(? AS int), CAST(? AS int))', [$namespace, self::lockKey($key)]);
    }

    private static function lockKey(string $key): int
    {
        $hash = crc32($key);

        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }
}

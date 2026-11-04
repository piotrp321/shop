<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Persistence\Doctrine;

use App\Cart\Application\Port\ExpiredCarts;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Deletes a batch with one statement on the connection, in the transaction of command.bus. The lines go with their
 * carts (ON DELETE CASCADE).
 *
 * The ids are chosen by expires_at (an index range scan) and locked with FOR UPDATE SKIP LOCKED: commands that change
 * a cart lock its row first and may renew expires_at (touch), so a cart one of them holds is skipped, not waited
 * for. A cart renewed and committed after the statement began is re-checked by FOR UPDATE against its new version
 * (READ COMMITTED) and left out; the outer condition repeats expires_at as a guard that a renewed cart is never
 * deleted.
 */
final readonly class DbalExpiredCarts implements ExpiredCarts
{
    private const string SQL = <<<'SQL'
        DELETE FROM cart_cart
        WHERE id IN (
            SELECT id FROM cart_cart WHERE expires_at < :now ORDER BY expires_at, id LIMIT :limit FOR UPDATE SKIP LOCKED
        ) AND expires_at < :now
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function purgeBatch(\DateTimeImmutable $now, int $limit): int
    {
        $removed = $this->connection->executeStatement(
            self::SQL,
            ['now' => $now, 'limit' => $limit],
            ['now' => Types::DATETIMETZ_IMMUTABLE, 'limit' => ParameterType::INTEGER],
        );
        \assert(\is_int($removed));

        return $removed;
    }
}

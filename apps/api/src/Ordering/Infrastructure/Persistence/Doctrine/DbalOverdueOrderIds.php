<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\OverdueOrder;
use App\Ordering\Application\Port\OverdueOrderIds;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads the overdue new orders through the index ordering_order_status_placed_at_id_idx (status, placed_at DESC, id
 * DESC), walked backwards: new orders are only the open ones (plus those an expiry failed for), so the range of the
 * status is short whatever the size of the table, and the keyset (placed_at, id) continues it without an offset. The
 * deadline filters the rows of the range (it is placed_at + 30 minutes, decided by the domain, not repeated here).
 *
 * The moments travel as text with microseconds both ways: placed_at written by SQL may have them, and a keyset cut to
 * the second would read the same order again.
 */
final readonly class DbalOverdueOrderIds implements OverdueOrderIds
{
    private const string MOMENT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function page(\DateTimeImmutable $deadlineBefore, ?OverdueOrder $after, int $limit): array
    {
        $keyset = null === $after ? '' : 'AND (placed_at, id) > (CAST(:afterPlacedAt AS timestamptz), CAST(:afterId AS uuid))';
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT id, placed_at
                FROM ordering_order
                WHERE status = 'new' AND payment_deadline < CAST(:deadlineBefore AS timestamptz) {$keyset}
                ORDER BY placed_at, id
                LIMIT :limit
                SQL,
            [
                'deadlineBefore' => $deadlineBefore->format(self::MOMENT),
                'limit' => $limit,
                ...(null === $after ? [] : ['afterPlacedAt' => $after->placedAt->format(self::MOMENT), 'afterId' => $after->orderId]),
            ],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static function (array $row): OverdueOrder {
            \assert(\is_string($row['id']) && \is_string($row['placed_at']));

            return new OverdueOrder($row['id'], new \DateTimeImmutable($row['placed_at']));
        }, $rows);
    }
}

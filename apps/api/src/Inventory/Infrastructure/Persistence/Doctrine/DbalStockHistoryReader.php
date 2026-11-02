<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine;

use App\Inventory\Application\Port\StockHistoryReader;
use App\Inventory\Application\Query\StockHistoryCursor;
use App\Inventory\Application\Query\StockHistoryItem;
use App\Inventory\Domain\Stock\ProductId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads straight on the connection, one statement per page. The index (product_id, occurred_at DESC, id) serves the
 * product and the time order; the id is descending, so the later of two entries within one second comes first (the
 * ids are time-ordered, see DoctrineStockItemRepository), which the database finishes by sorting the few rows of
 * one second. The cursor is a row comparison of the same two columns in the same direction; the plain bound on the
 * time repeats its first half, because a row comparison alone cannot use an index whose columns run in mixed
 * directions. Nothing here writes. The id of the actor is not even selected.
 */
final readonly class DbalStockHistoryReader implements StockHistoryReader
{
    private const string COLUMNS = 'id, occurred_at, type, physical_delta, reserved_delta, physical_after, reserved_after, reason, note, reference, source, actor_name';

    public function __construct(private Connection $connection)
    {
    }

    public function page(ProductId $productId, ?StockHistoryCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be at least 1.');
        }

        $sql = 'SELECT '.self::COLUMNS.' FROM inventory_stock_movement WHERE product_id = CAST(:product AS uuid)';
        $parameters = ['product' => $productId->toString(), 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $after) {
            $sql .= ' AND occurred_at <= CAST(:after_time AS timestamptz) AND (occurred_at, id) < (CAST(:after_time AS timestamptz), CAST(:after_id AS uuid))';
            $parameters['after_time'] = $after->occurredAt->format('Y-m-d H:i:s.uP');
            $parameters['after_id'] = $after->id;
        }
        $rows = $this->connection->fetchAllAssociative($sql.' ORDER BY occurred_at DESC, id DESC LIMIT :limit', $parameters, $types);

        return array_map($this->fromRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row the columns of self::COLUMNS
     */
    private function fromRow(array $row): StockHistoryItem
    {
        \assert(\is_string($row['id']) && \is_string($row['occurred_at']) && \is_string($row['type']) && \is_string($row['source']));
        \assert(\is_int($row['physical_delta']) && \is_int($row['reserved_delta']) && \is_int($row['physical_after']) && \is_int($row['reserved_after']));
        \assert(null === $row['reason'] || \is_string($row['reason']));
        \assert(null === $row['note'] || \is_string($row['note']));
        \assert(null === $row['reference'] || \is_string($row['reference']));
        \assert(null === $row['actor_name'] || \is_string($row['actor_name']));

        return new StockHistoryItem(
            $row['id'],
            new \DateTimeImmutable($row['occurred_at']),
            $row['type'],
            $row['physical_delta'],
            $row['reserved_delta'],
            $row['physical_after'],
            $row['reserved_after'],
            $row['reason'],
            $row['note'],
            $row['reference'],
            $row['source'],
            $row['actor_name'],
        );
    }
}

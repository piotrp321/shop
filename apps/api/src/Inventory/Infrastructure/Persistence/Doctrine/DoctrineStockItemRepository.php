<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine;

use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockMovement;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Maps the StockItem aggregate to and from its row (see Record\StockItemRecord) and appends its movements (see
 * Record\StockMovementRecord), with plain statements on the connection of the entity manager, so they join the
 * transaction of command.bus. No identity map: a row is always read as stored, and a rolled-back write leaves no
 * managed record behind. The locking and the versioned write are explicit SQL.
 */
final readonly class DoctrineStockItemRepository implements StockItemRepository
{
    private const string COLUMNS = 'product_id, physical, reserved, version, updated_at';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * One statement. PostgreSQL sorts the rows by the primary key before it locks them (LockRows above the ordered
     * scan), so the locks are taken in ascending product id whatever the order of $ids. Under READ COMMITTED a row
     * changed by the transaction that held its lock is returned as that transaction committed it, so the stock is
     * read anew once locked.
     */
    public function lockMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM inventory_stock_item WHERE product_id = ANY(CAST(:ids AS uuid[])) ORDER BY product_id FOR UPDATE',
            ['ids' => self::uuidArray($ids)],
        );

        return array_map($this->fromRow(...), $rows);
    }

    /**
     * One statement, `product_id = ANY(:ids)`, without a lock.
     */
    public function findMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM inventory_stock_item WHERE product_id = ANY(CAST(:ids AS uuid[]))',
            ['ids' => self::uuidArray($ids)],
        );

        return array_map($this->fromRow(...), $rows);
    }

    /**
     * The stock was read at version() − 1 (all its unsaved changes share one version step). The UPDATE compares that
     * version, so a write that did not hold the row lock and lost a race cannot pass silently; the CHECK constraint
     * of the table refuses a state breaking the invariant. The movements are inserted by the same transaction, so
     * there is no change without its movement and no movement without its change.
     */
    public function save(StockItem $item): void
    {
        $movements = $item->releaseMovements();
        if ([] === $movements) {
            return;
        }

        $updated = $this->connection->executeStatement(
            'UPDATE inventory_stock_item SET physical = :physical, reserved = :reserved, version = :version, updated_at = :updated_at WHERE product_id = :product_id AND version = :read_version',
            [
                'physical' => $item->physical(),
                'reserved' => $item->reserved(),
                'version' => $item->version(),
                'updated_at' => $item->updatedAt(),
                'product_id' => $item->id()->toString(),
                'read_version' => $item->version() - 1,
            ],
            ['updated_at' => Types::DATETIMETZ_IMMUTABLE],
        );
        if (1 !== $updated) {
            throw new \LogicException('The stock is not stored at the version it was read with: it was changed without holding its lock.');
        }

        foreach ($movements as $movement) {
            $this->append($movement);
        }
    }

    private function append(StockMovement $movement): void
    {
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_movement (id, product_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reason, note, reference, source, actor_id, actor_name, occurred_at) '
            .'VALUES (:id, :product_id, :type, :physical_delta, :reserved_delta, :physical_after, :reserved_after, :reason, :note, :reference, :source, :actor_id, :actor_name, :occurred_at)',
            [
                // Time-ordered, so the movements of one second keep their order in the history.
                'id' => Uuid::v7()->toRfc4122(),
                'product_id' => $movement->productId()->toString(),
                'type' => $movement->type()->value,
                'physical_delta' => $movement->physicalDelta(),
                'reserved_delta' => $movement->reservedDelta(),
                'physical_after' => $movement->physicalAfter(),
                'reserved_after' => $movement->reservedAfter(),
                'reason' => $movement->reason(),
                'note' => $movement->note(),
                'reference' => $movement->reference(),
                'source' => $movement->origin()->source()->value,
                'actor_id' => $movement->origin()->actorId(),
                'actor_name' => $movement->origin()->actorName(),
                'occurred_at' => $movement->occurredAt(),
            ],
            ['occurred_at' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * A PostgreSQL array literal of validated UUIDs (hex digits and dashes only), bound as one parameter.
     *
     * @param list<ProductId> $ids
     */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', array_map(static fn (ProductId $id): string => $id->toString(), $ids)).'}';
    }

    /**
     * @param array<string, mixed> $row the columns of self::COLUMNS
     */
    private function fromRow(array $row): StockItem
    {
        \assert(\is_string($row['product_id']) && \is_string($row['updated_at']));
        \assert(\is_int($row['physical']) && \is_int($row['reserved']) && \is_int($row['version']));

        return StockItem::reconstitute(
            ProductId::fromString($row['product_id']),
            $row['physical'],
            $row['reserved'],
            $row['version'],
            new \DateTimeImmutable($row['updated_at']),
        );
    }
}

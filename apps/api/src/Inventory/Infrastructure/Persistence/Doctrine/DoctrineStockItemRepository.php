<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine;

use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\ReceiptKey;
use App\Inventory\Domain\Stock\ReceiptKeyReused;
use App\Inventory\Domain\Stock\StockChangeOrigin;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockMovement;
use App\Inventory\Domain\Stock\StockMovementType;
use App\Inventory\Infrastructure\Persistence\Doctrine\Record\StockMovementRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
     * INSERT … ON CONFLICT DO NOTHING of a row with zeros at version 0, then SELECT … FOR UPDATE. PostgreSQL makes an
     * insert of a key that another, uncommitted transaction inserted wait for it; once that one ends, the insert
     * either adds the row (the other rolled back) or skips it, and the lock reads the row as the other committed it.
     * The row inserted here is locked by this transaction anyway.
     */
    public function lockOrCreate(ProductId $id): StockItem
    {
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (product_id, physical, reserved, version, updated_at) VALUES (:product_id, 0, 0, 0, :now) ON CONFLICT (product_id) DO NOTHING',
            ['product_id' => $id->toString(), 'now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );

        return $this->lockMany([$id])[0] ?? throw new \LogicException('A stock just created or found is not stored.');
    }

    /**
     * One lookup of the partial unique index of receipt keys.
     */
    public function findReceipt(ReceiptKey $key): ?StockMovement
    {
        $row = $this->connection->fetchAssociative(
            "SELECT product_id, physical_delta, reserved_delta, physical_after, reserved_after, note, reference, source, actor_id, actor_name, occurred_at FROM inventory_stock_movement WHERE type = 'receipt' AND reference = :reference",
            ['reference' => $key->toString()],
        );
        if (false === $row) {
            return null;
        }
        \assert(\is_string($row['product_id']) && \is_string($row['source']) && \is_string($row['occurred_at']));
        \assert(\is_int($row['physical_delta']) && \is_int($row['reserved_delta']) && \is_int($row['physical_after']) && \is_int($row['reserved_after']));
        \assert((null === $row['note'] || \is_string($row['note'])) && \is_string($row['reference']));
        \assert((null === $row['actor_id'] || \is_string($row['actor_id'])) && (null === $row['actor_name'] || \is_string($row['actor_name'])));

        return new StockMovement(
            ProductId::fromString($row['product_id']),
            StockMovementType::Receipt,
            $row['physical_delta'],
            $row['reserved_delta'],
            $row['physical_after'],
            $row['reserved_after'],
            null,
            $row['note'],
            $row['reference'],
            match (StockChangeSource::from($row['source'])) {
                StockChangeSource::Staff => StockChangeOrigin::staff($row['actor_id'] ?? '', $row['actor_name'] ?? ''),
                StockChangeSource::Seed => StockChangeOrigin::seed(),
                StockChangeSource::System => StockChangeOrigin::system(),
            },
            new \DateTimeImmutable($row['occurred_at']),
        );
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
     * there is no change without its movement and no movement without its change. The partial unique index of receipt
     * keys refuses a receipt with a key another transaction stored after this one looked it up (two products received
     * with one key at the same time): the transaction fails as ReceiptKeyReused and must be rolled back.
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

    /**
     * @throws ReceiptKeyReused
     */
    private function append(StockMovement $movement): void
    {
        try {
            $this->insert($movement);
        } catch (UniqueConstraintViolationException $violation) {
            if (StockMovementType::Receipt === $movement->type() && str_contains($violation->getMessage(), StockMovementRecord::RECEIPT_REFERENCE_INDEX)) {
                throw new ReceiptKeyReused($violation);
            }

            throw $violation;
        }
    }

    private function insert(StockMovement $movement): void
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

<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\BackofficeOrderReader;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads straight on the connection, one statement per page, without locks; nothing here writes. The conditions are
 * constant fragments joined by AND; input only ever reaches the statement as parameters. Indexes (migration
 * Version20261107150000): (placed_at DESC, id DESC) and (status, placed_at DESC, id DESC) serve the newest first with
 * and without the status; the partial (refund_due_at, id) of the pending refunds serves their sort; the number has
 * its unique index and the email the index on lower(email), which the condition repeats as it is. The cursor is a row
 * comparison of the columns of the sort in its direction, so it stays an index condition. The units of an order are
 * summed from its lines (index on order_id), only for the orders of the page.
 */
final readonly class DbalBackofficeOrderReader implements BackofficeOrderReader
{
    private const string COLUMNS = 'o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, '
        .'(SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, '
        .'o.refund_amount, o.refund_due_at, o.refund_completed_at';

    private const string STATUS = 'o.status = :status';
    private const string PENDING_REFUND = 'o.refund_amount IS NOT NULL AND o.refund_completed_at IS NULL';
    private const string NUMBER = 'o.number = :number';
    private const string EMAIL = 'lower(o.email) = lower(:email)';
    private const string AFTER_PLACEMENT = '(o.placed_at, o.id) < (CAST(:after_at AS timestamptz), CAST(:after_id AS uuid))';
    private const string AFTER_REFUND_DUE_DATE = '(o.refund_due_at, o.id) > (CAST(:after_at AS timestamptz), CAST(:after_id AS uuid))';

    public function __construct(private Connection $connection)
    {
    }

    public function page(BackofficeOrderFilter $filter, ?BackofficeOrderCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }
        if (null !== $after && $after->byRefundDueDate !== $filter->pendingRefunds) {
            throw new \InvalidArgumentException('The cursor is of the other sort.');
        }

        $conditions = [];
        $parameters = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $filter->status) {
            $conditions[] = self::STATUS;
            $parameters['status'] = $filter->status->value;
        }
        if ($filter->pendingRefunds) {
            $conditions[] = self::PENDING_REFUND;
        }
        if (null !== $filter->number) {
            $conditions[] = self::NUMBER;
            $parameters['number'] = $filter->number;
        }
        if (null !== $filter->email) {
            $conditions[] = self::EMAIL;
            $parameters['email'] = $filter->email;
        }
        if (null !== $after) {
            $conditions[] = $filter->pendingRefunds ? self::AFTER_REFUND_DUE_DATE : self::AFTER_PLACEMENT;
            $parameters['after_at'] = $after->at->format('Y-m-d H:i:s.uP');
            $parameters['after_id'] = $after->id;
        }

        $sql = 'SELECT '.self::COLUMNS.' FROM ordering_order o'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .($filter->pendingRefunds ? ' ORDER BY o.refund_due_at ASC, o.id ASC' : ' ORDER BY o.placed_at DESC, o.id DESC')
            .' LIMIT :limit';

        return array_map($this->fromRow(...), $this->connection->fetchAllAssociative($sql, $parameters, $types));
    }

    /**
     * @param array<string, mixed> $row the columns of self::COLUMNS
     */
    private function fromRow(array $row): ListedOrder
    {
        \assert(\is_string($row['id']) && \is_string($row['number']) && \is_string($row['placed_at']) && \is_string($row['status']));
        \assert(\is_string($row['email']) && \is_string($row['full_name']) && \is_int($row['total_gross']) && \is_string($row['currency']));
        \assert(\is_int($row['item_count']));
        \assert(null === $row['refund_amount'] || \is_int($row['refund_amount']));
        \assert(null === $row['refund_due_at'] || \is_string($row['refund_due_at']));
        \assert(null === $row['refund_completed_at'] || \is_string($row['refund_completed_at']));

        return new ListedOrder(
            $row['id'],
            $row['number'],
            new \DateTimeImmutable($row['placed_at']),
            $row['status'],
            $row['email'],
            $row['full_name'],
            $row['total_gross'],
            $row['currency'],
            $row['item_count'],
            $row['refund_amount'],
            null === $row['refund_due_at'] ? null : new \DateTimeImmutable($row['refund_due_at']),
            null === $row['refund_completed_at'] ? null : new \DateTimeImmutable($row['refund_completed_at']),
        );
    }
}

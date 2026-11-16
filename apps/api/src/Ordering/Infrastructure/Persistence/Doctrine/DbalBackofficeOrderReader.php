<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\BackofficeOrderReader;
use App\Ordering\Application\Port\ListedOrder;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads straight on the connection, one statement per page, without locks; nothing here writes. The conditions are
 * constant fragments joined by AND; input only ever reaches the statement as parameters. Indexes (migrations
 * Version20261107150000, Version20261115130100 and Version20261115140000): (placed_at DESC, id DESC) and (status, placed_at DESC, id DESC)
 * serve the sort by placement with and without the status, (total_gross, id) and (status, total_gross, id) the sort by
 * the amount; both directions of a sort read the same index, forwards or backwards, because the id follows the
 * direction of the sorted column. The partial (refund_due_at, id) of the pending refunds serves their sort, and the overdue ones (due before a moment) as a range of it; the number
 * has its unique index and the email the index on lower(email), which the condition repeats as it is; the orders of a
 * customer the partial (customer_id, placed_at DESC, id DESC) of the orders of customers, whose condition is repeated. The cursor is a
 * row comparison of the columns of the sort in its direction, so it stays an index condition. The units of an order are
 * summed from its lines (index on order_id), only for the orders of the page.
 */
final readonly class DbalBackofficeOrderReader implements BackofficeOrderReader
{
    private const string COLUMNS = 'o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency, '
        .'(SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count, '
        .'o.refund_amount, o.refund_due_at, o.refund_completed_at';

    private const string STATUS = 'o.status = :status';
    private const string PENDING_REFUND = 'o.refund_amount IS NOT NULL AND o.refund_completed_at IS NULL';
    private const string REFUND_DUE_BEFORE = 'o.refund_due_at < :refund_due_before';
    private const string NUMBER = 'o.number = :number';
    private const string EMAIL = 'lower(o.email) = lower(:email)';
    /** The expression of the index ordering_order_search_trgm_idx, which the planner recognises as it is; U+001F separates the fields. */
    private const string FRAGMENT = "lower(o.number || E'\\x1f' || o.email || E'\\x1f' || o.full_name) LIKE '%' || lower(:fragment) || '%'";
    private const string PLACED_FROM = 'o.placed_at >= :placed_from';
    private const string PLACED_BEFORE = 'o.placed_at < :placed_before';
    /** The condition of the partial index (customer_id, placed_at DESC, id DESC) WHERE owner_type = 'customer'. */
    private const string CUSTOMER = "o.owner_type = 'customer' AND o.customer_id = :customer_id";
    /** The sorted column of every sort, by the key of the sort, and the type of the position its cursor holds. */
    private const array SORT_COLUMNS = [
        '-placedAt' => ['o.placed_at', 'timestamptz'],
        'placedAt' => ['o.placed_at', 'timestamptz'],
        '-total' => ['o.total_gross', 'bigint'],
        'total' => ['o.total_gross', 'bigint'],
        'refund' => ['o.refund_due_at', 'timestamptz'],
    ];

    public function __construct(private Connection $connection, private BoundedCounter $counter)
    {
    }

    public function page(BackofficeOrderFilter $filter, ?BackofficeOrderCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }
        if (null !== $after && $after->sort !== $filter->sort) {
            throw new \InvalidArgumentException('The cursor is of the other sort.');
        }
        [$column, $type] = self::SORT_COLUMNS[$filter->sort->value];
        $direction = $filter->sort->isDescending() ? 'DESC' : 'ASC';

        [$conditions, $parameters] = self::conditions($filter);
        $parameters['limit'] = $limit;
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $after) {
            $conditions[] = \sprintf('(%s, o.id) %s (CAST(:after_value AS %s), CAST(:after_id AS uuid))', $column, $filter->sort->isDescending() ? '<' : '>', $type);
            $parameters['after_value'] = $after->value instanceof \DateTimeImmutable ? $after->value->format('Y-m-d H:i:s.uP') : $after->value;
            $parameters['after_id'] = $after->id;
        }

        $sql = 'SELECT '.self::COLUMNS.' FROM ordering_order o'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            ." ORDER BY {$column} {$direction}, o.id {$direction} LIMIT :limit";

        return array_map($this->fromRow(...), $this->connection->fetchAllAssociative($sql, $parameters, $types));
    }

    public function count(BackofficeOrderFilter $filter, int $limit = BoundedCounter::DEFAULT_LIMIT): BoundedCount
    {
        [$conditions, $parameters] = self::conditions($filter);

        return $this->counter->count('ordering_order o', $conditions, $parameters, limit: $limit);
    }

    /**
     * The conditions of the filter, the same for a page and for the count, without the cursor.
     *
     * @return array{list<string>, array<string, mixed>} the conditions and the values they bind
     */
    private static function conditions(BackofficeOrderFilter $filter): array
    {
        $conditions = [];
        $parameters = [];
        if (null !== $filter->status) {
            $conditions[] = self::STATUS;
            $parameters['status'] = $filter->status->value;
        }
        if ($filter->pendingRefunds) {
            $conditions[] = self::PENDING_REFUND;
        }
        if (null !== $filter->refundDueBefore) {
            // refund_due_at leads the partial index of the pending refunds, so this is a range of that index.
            $conditions[] = self::REFUND_DUE_BEFORE;
            $parameters['refund_due_before'] = $filter->refundDueBefore->format('Y-m-d H:i:s.uP');
        }
        if (null !== $filter->number) {
            $conditions[] = self::NUMBER;
            $parameters['number'] = $filter->number;
        }
        if (null !== $filter->email) {
            $conditions[] = self::EMAIL;
            $parameters['email'] = $filter->email;
        }
        if (null !== $filter->fragment) {
            $conditions[] = self::FRAGMENT;
            // The escape character of LIKE is the backslash: the characters of the pattern mean themselves.
            $parameters['fragment'] = strtr($filter->fragment, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
        }
        if (null !== $filter->placedFrom) {
            $conditions[] = self::PLACED_FROM;
            $parameters['placed_from'] = $filter->placedFrom->format('Y-m-d H:i:s.uP');
        }
        if (null !== $filter->placedBefore) {
            $conditions[] = self::PLACED_BEFORE;
            $parameters['placed_before'] = $filter->placedBefore->format('Y-m-d H:i:s.uP');
        }
        if (null !== $filter->customerId) {
            $conditions[] = self::CUSTOMER;
            $parameters['customer_id'] = $filter->customerId;
        }

        return [$conditions, $parameters];
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

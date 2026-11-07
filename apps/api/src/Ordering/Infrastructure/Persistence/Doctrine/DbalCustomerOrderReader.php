<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\CustomerListedOrder;
use App\Ordering\Application\Port\CustomerOrderReader;
use App\Ordering\Application\Query\BackofficeOrderCursor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads straight on the connection, one statement per page, without locks; nothing here writes. Only the orders of
 * the customer (`owner_type = 'customer'` and the `sub`), so a guest order or another customer's is never read. The
 * partial index (customer_id, placed_at DESC, id DESC) WHERE owner_type = 'customer' (migration
 * Version20261107160000) serves the sort; the cursor is a row comparison of its columns, so it stays an index
 * condition. The units of an order are summed from its lines (index on order_id), only for the orders of the page.
 * Input only ever reaches the statement as parameters.
 */
final readonly class DbalCustomerOrderReader implements CustomerOrderReader
{
    private const string COLUMNS = 'o.id, o.number, o.placed_at, o.status, o.total_gross, o.currency, o.payment_deadline, '
        .'(SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count';

    private const string AFTER = '(o.placed_at, o.id) < (CAST(:after_at AS timestamptz), CAST(:after_id AS uuid))';

    public function __construct(private Connection $connection)
    {
    }

    public function page(string $customerId, ?BackofficeOrderCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }
        if (null !== $after && $after->byRefundDueDate) {
            throw new \InvalidArgumentException('The cursor is of the other sort.');
        }
        // The driver cuts a parameter at a NUL, which would silently look for another customer.
        if (str_contains($customerId, "\0")) {
            return [];
        }

        $conditions = ["o.owner_type = 'customer'", 'o.customer_id = :customer'];
        $parameters = ['customer' => $customerId, 'limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if (null !== $after) {
            $conditions[] = self::AFTER;
            $parameters['after_at'] = $after->at->format('Y-m-d H:i:s.uP');
            $parameters['after_id'] = $after->id;
        }

        $sql = 'SELECT '.self::COLUMNS.' FROM ordering_order o WHERE '.implode(' AND ', $conditions)
            .' ORDER BY o.placed_at DESC, o.id DESC LIMIT :limit';

        return array_map($this->fromRow(...), $this->connection->fetchAllAssociative($sql, $parameters, $types));
    }

    /**
     * @param array<string, mixed> $row the columns of self::COLUMNS
     */
    private function fromRow(array $row): CustomerListedOrder
    {
        \assert(\is_string($row['id']) && \is_string($row['number']) && \is_string($row['placed_at']) && \is_string($row['status']));
        \assert(\is_int($row['total_gross']) && \is_string($row['currency']) && \is_string($row['payment_deadline']) && \is_int($row['item_count']));

        return new CustomerListedOrder(
            $row['id'],
            $row['number'],
            new \DateTimeImmutable($row['placed_at']),
            $row['status'],
            $row['total_gross'],
            $row['currency'],
            $row['item_count'],
            new \DateTimeImmutable($row['payment_deadline']),
        );
    }
}

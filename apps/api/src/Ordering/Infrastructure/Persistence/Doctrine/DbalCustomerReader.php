<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\BackofficeCustomerReader;
use App\Ordering\Application\Port\CustomerSummary;
use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Application\Query\BackofficeCustomerCursor;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\View\ShippingAddressView;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads ordering_customer straight on the connection (the summaries are written by DBAL, see DbalCustomerSummaries),
 * one statement per page, without locks; nothing here writes. Every sort has its index (migration
 * Version20261116090000CreateCustomers): (last_order_at DESC, customer_id), (lower(full_name), customer_id),
 * (order_count DESC, customer_id) and (paid_gross DESC, customer_id); the customer id breaks the ties ascending, so the
 * cursor of a descending sort is `column <= value AND (column < value OR customer_id > id)`, whose first part is an
 * index condition, and the one of the name a row comparison. The fragment is the expressions of the trigram indexes
 * (Version20261116090100IndexCustomerSearch), lower(email) and lower(full_name), each as it is, so the planner joins
 * two bitmap scans. The addresses are read from the last orders of the customer on the partial index
 * (customer_id, placed_at DESC, id DESC) of the orders of customers.
 */
final readonly class DbalCustomerReader implements BackofficeCustomerReader
{
    private const string COLUMNS = 'c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at, lower(c.full_name) AS sort_name';
    private const string FRAGMENT = "(lower(c.email) LIKE '%' || lower(:fragment) || '%' OR lower(c.full_name) LIKE '%' || lower(:fragment) || '%')";
    /** The sorted expression of every sort, by the key of the sort, and the type of the value its cursor holds. */
    private const array SORT_COLUMNS = [
        '-lastOrderAt' => ['c.last_order_at', 'timestamptz'],
        'name' => ['lower(c.full_name)', 'text'],
        '-orderCount' => ['c.order_count', 'int'],
        '-paidGross' => ['c.paid_gross', 'bigint'],
    ];

    public function __construct(private Connection $connection, private BoundedCounter $counter)
    {
    }

    public function page(?string $fragment, BackofficeCustomerSort $sort, ?BackofficeCustomerCursor $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }
        if (null !== $after && $after->sort !== $sort) {
            throw new \InvalidArgumentException('The cursor is of another sort.');
        }
        [$column, $type] = self::SORT_COLUMNS[$sort->value];
        $descending = BackofficeCustomerSort::Name !== $sort;

        [$conditions, $parameters] = self::conditions($fragment);
        $parameters['limit'] = $limit;
        if (null !== $after) {
            $conditions[] = $descending
                ? \sprintf('%1$s <= CAST(:after_value AS %2$s) AND (%1$s < CAST(:after_value AS %2$s) OR c.customer_id > :after_id)', $column, $type)
                : \sprintf('(%s, c.customer_id) > (CAST(:after_value AS %s), :after_id)', $column, $type);
            $parameters['after_value'] = $after->value instanceof \DateTimeImmutable ? $after->value->format('Y-m-d H:i:s.uP') : $after->value;
            $parameters['after_id'] = $after->customerId;
        }

        $sql = 'SELECT '.self::COLUMNS.' FROM ordering_customer c'
            .([] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions))
            .\sprintf(' ORDER BY %s %s, c.customer_id LIMIT :limit', $column, $descending ? 'DESC' : 'ASC');

        return array_map(self::listed(...), $this->connection->fetchAllAssociative($sql, $parameters, ['limit' => ParameterType::INTEGER]));
    }

    public function count(?string $fragment, int $limit = BoundedCounter::DEFAULT_LIMIT): BoundedCount
    {
        [$conditions, $parameters] = self::conditions($fragment);

        return $this->counter->count('ordering_customer c', $conditions, $parameters, limit: $limit);
    }

    public function find(string $customerId): ?CustomerSummary
    {
        $row = $this->connection->fetchAssociative(
            'SELECT customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at FROM ordering_customer WHERE customer_id = ?',
            [$customerId],
        );
        if (false === $row) {
            return null;
        }
        \assert(\is_string($row['customer_id']) && \is_string($row['email']) && \is_string($row['full_name']) && \is_string($row['phone']));
        \assert(\is_int($row['order_count']) && \is_int($row['paid_count']) && \is_int($row['paid_gross']));
        \assert(\is_string($row['first_order_at']) && \is_string($row['last_order_at']));

        return new CustomerSummary(
            $row['customer_id'],
            $row['email'],
            $row['full_name'],
            $row['phone'],
            $row['order_count'],
            $row['paid_count'],
            $row['paid_gross'],
            new \DateTimeImmutable($row['first_order_at']),
            new \DateTimeImmutable($row['last_order_at']),
        );
    }

    public function addresses(string $customerId, int $orders, int $limit): array
    {
        if ($orders < 1 || $limit < 1) {
            throw new \InvalidArgumentException('The numbers of orders and of addresses must be at least 1.');
        }
        // The last orders on the partial index, then each address once with the order that used it last.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT street, postal_code, city, country FROM (
                 SELECT DISTINCT ON (street, postal_code, city, country) street, postal_code, city, country, placed_at, id FROM (
                     SELECT o.street, o.postal_code, o.city, o.country, o.placed_at, o.id FROM ordering_order o
                     WHERE o.owner_type = 'customer' AND o.customer_id = :customer
                     ORDER BY o.placed_at DESC, o.id DESC LIMIT :orders
                 ) AS recent
                 ORDER BY street, postal_code, city, country, placed_at DESC, id DESC
             ) AS used ORDER BY placed_at DESC, id DESC LIMIT :limit",
            ['customer' => $customerId, 'orders' => $orders, 'limit' => $limit],
            ['orders' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        return array_map(static function (array $row): ShippingAddressView {
            \assert(\is_string($row['street']) && \is_string($row['postal_code']) && \is_string($row['city']) && \is_string($row['country']));

            return new ShippingAddressView($row['street'], $row['postal_code'], $row['city'], $row['country']);
        }, $rows);
    }

    /**
     * The condition of the fragment, the same for a page and for the count, without the cursor.
     *
     * @return array{list<string>, array<string, mixed>}
     */
    private static function conditions(?string $fragment): array
    {
        if (null === $fragment) {
            return [[], []];
        }

        // The escape character of LIKE is the backslash: the characters of the pattern mean themselves.
        return [[self::FRAGMENT], ['fragment' => strtr($fragment, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_'])]];
    }

    /**
     * @param array<string, mixed> $row the columns of self::COLUMNS
     */
    private static function listed(array $row): ListedCustomer
    {
        \assert(\is_string($row['customer_id']) && \is_string($row['email']) && \is_string($row['full_name']) && \is_string($row['sort_name']));
        \assert(\is_int($row['order_count']) && \is_int($row['paid_gross']) && \is_string($row['last_order_at']));

        return new ListedCustomer($row['customer_id'], $row['email'], $row['full_name'], $row['order_count'], $row['paid_gross'], new \DateTimeImmutable($row['last_order_at']), $row['sort_name']);
    }
}

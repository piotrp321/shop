<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\CustomerRebuildTotals;
use App\Ordering\Application\Port\CustomerSummaries;
use App\Ordering\Application\Port\CustomerSummariesRebuild;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\OrderId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * ordering_customer through DBAL, not through records of the ORM (CustomerRecord only defines the schema), like the
 * hourly sales: an addition is one upsert that joins the transaction of the caller, so concurrent orders and payments
 * of one customer wait for each other's row instead of failing on its key, and a rolled-back placement or webhook rolls
 * its addition back.
 *
 * The contact is the one of the newest order: an order takes the row's contact only when (placed_at, id) is not before
 * (last_order_at, last_order_id), so orders committed out of their order (two instances of the API, a clock a second
 * apart) leave the contact of the newest one, the one the rebuild takes (ORDER BY placed_at DESC, id DESC).
 *
 * The rebuild of a batch of customers (a range of customer ids) runs in a transaction of its own (READ COMMITTED, the
 * default): it creates the missing rows of the customers of the range with committed orders (placeholders), locks every
 * row of the range it sees, and only then counts the orders of the customers of exactly the rows it locked, each
 * statement on what has committed by its start. A placement or a payment adds to the row of its customer in its own
 * transaction before it commits, so it either committed before the lock was granted (the count sees its order and the
 * rewrite replaces its addition) or waits for the rebuild to commit and adds itself to the rewritten row. A row the
 * lock did not see belongs to a customer whose every order committed after the placeholders were made: the rewrite
 * leaves it alone (a rewrite from a count taken after the lock, on a row another transaction updates meanwhile, would
 * write the count without that update), and what its placements and payments added is complete. No order is lost or
 * counted twice. The rows of the range without orders are deleted before the commit, so the table holds what the
 * placements and payments alone would have written. The orders of a range are read on the partial index
 * (customer_id, placed_at DESC, id DESC) of the orders of customers.
 */
final readonly class DbalCustomerSummaries implements CustomerSummaries, CustomerSummariesRebuild
{
    private const string CUSTOMER_ORDERS = "o.owner_type = 'customer'";
    /** The id of the rows a rebuild creates before it counts (they never outlive its transaction). */
    private const string PLACEHOLDER_ORDER = '00000000-0000-0000-0000-000000000000';

    /** The added order is not before the newest one of the row: its contact becomes the customer's. */
    private const string NEWER = '(EXCLUDED.last_order_at, EXCLUDED.last_order_id) >= (c.last_order_at, c.last_order_id)';

    public function __construct(private Connection $connection)
    {
    }

    public function addOrder(string $customerId, OrderId $orderId, Contact $contact, \DateTimeImmutable $placedAt): void
    {
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO ordering_customer AS c (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
                 VALUES (:customer, :email, :full_name, :phone, 1, 0, 0, :placed_at, :placed_at, :order)
                 ON CONFLICT (customer_id) DO UPDATE SET
                     order_count = c.order_count + 1,
                     first_order_at = LEAST(c.first_order_at, EXCLUDED.first_order_at),
                     last_order_at = GREATEST(c.last_order_at, EXCLUDED.last_order_at),
                     email = CASE WHEN %1$s THEN EXCLUDED.email ELSE c.email END,
                     full_name = CASE WHEN %1$s THEN EXCLUDED.full_name ELSE c.full_name END,
                     phone = CASE WHEN %1$s THEN EXCLUDED.phone ELSE c.phone END,
                     last_order_id = CASE WHEN %1$s THEN EXCLUDED.last_order_id ELSE c.last_order_id END',
                self::NEWER,
            ),
            [
                'customer' => $customerId,
                'email' => $contact->email()->toString(),
                'full_name' => $contact->fullName(),
                'phone' => $contact->phone(),
                'placed_at' => $placedAt,
                'order' => $orderId->toString(),
            ],
            ['placed_at' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    public function addPayment(string $customerId, OrderId $orderId, int $gross): void
    {
        // A customer without a row has orders placed before the rollout that the backfill has not reached yet: the row
        // is made from the paid order itself (its contact and moment, counted once and paid), never a row of NULLs.
        $this->connection->executeStatement(
            "INSERT INTO ordering_customer AS c (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
             SELECT o.customer_id, o.email, o.full_name, o.phone, 1, 1, :gross, o.placed_at, o.placed_at, o.id
             FROM ordering_order o WHERE o.id = CAST(:order AS uuid) AND o.owner_type = 'customer' AND o.customer_id = :customer
             ON CONFLICT (customer_id) DO UPDATE SET paid_count = c.paid_count + 1, paid_gross = c.paid_gross + EXCLUDED.paid_gross",
            ['customer' => $customerId, 'order' => $orderId->toString(), 'gross' => $gross],
            ['gross' => Types::BIGINT],
        );
    }

    public function nextBatchEnd(?string $after, int $size): ?string
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('A batch has at least one customer.');
        }
        // Each side is one walk of its index (the partial index of the orders of customers, the primary key).
        $last = $this->connection->fetchOne(
            \sprintf(
                'SELECT MAX(customer_id) FROM (
                     SELECT customer_id FROM (
                         (SELECT DISTINCT o.customer_id FROM ordering_order o WHERE %1$s%2$s ORDER BY o.customer_id LIMIT :size)
                         UNION
                         (SELECT c.customer_id FROM ordering_customer c WHERE TRUE%3$s ORDER BY c.customer_id LIMIT :size)
                     ) AS both_sides ORDER BY customer_id LIMIT :size
                 ) AS batch',
                self::CUSTOMER_ORDERS,
                null === $after ? '' : ' AND o.customer_id > :after',
                null === $after ? '' : ' AND c.customer_id > :after',
            ),
            null === $after ? ['size' => $size] : ['size' => $size, 'after' => $after],
            ['size' => ParameterType::INTEGER],
        );
        \assert(null === $last || \is_string($last));

        return $last;
    }

    public function rebuild(?string $after, string $last): CustomerRebuildTotals
    {
        $parameters = null === $after ? ['last' => $last] : ['last' => $last, 'after' => $after];
        $orders = \sprintf('%s AND o.customer_id <= :last%s', self::CUSTOMER_ORDERS, null === $after ? '' : ' AND o.customer_id > :after');
        $rows = 'c.customer_id <= :last'.(null === $after ? '' : ' AND c.customer_id > :after');

        return $this->connection->transactional(static function (Connection $connection) use ($parameters, $orders, $rows): CustomerRebuildTotals {
            $connection->executeStatement(
                \sprintf(
                    "INSERT INTO ordering_customer (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
                     SELECT DISTINCT o.customer_id, '', '', '', 0, 0, 0, now(), now(), CAST('%s' AS uuid) FROM ordering_order o WHERE %s ORDER BY o.customer_id
                     ON CONFLICT (customer_id) DO NOTHING",
                    self::PLACEHOLDER_ORDER,
                    $orders,
                ),
                $parameters,
            );
            // The placeholders are made and the rows locked in the order of their ids, so two rebuilds of one range wait
            // for each other's rows, never in a cycle.
            $locked = $connection->fetchFirstColumn(\sprintf('SELECT c.customer_id FROM ordering_customer c WHERE %s ORDER BY c.customer_id FOR UPDATE', $rows), $parameters);
            // Counted only now, under the lock: what committed before it is counted, what did not waits for it. Only the
            // locked rows are rewritten.
            $parameters['locked'] = $locked;
            $types = ['locked' => ArrayParameterType::STRING];
            $counts = $connection->fetchFirstColumn(
                \sprintf(
                    'UPDATE ordering_customer AS c SET
                         email = n.email, full_name = n.full_name, phone = n.phone, last_order_id = n.id,
                         order_count = a.order_count, paid_count = a.paid_count, paid_gross = a.paid_gross,
                         first_order_at = a.first_order_at, last_order_at = a.last_order_at
                     FROM (
                         SELECT o.customer_id, COUNT(*) AS order_count, COUNT(o.paid_at) AS paid_count,
                                COALESCE(SUM(o.total_gross) FILTER (WHERE o.paid_at IS NOT NULL), 0) AS paid_gross,
                                MIN(o.placed_at) AS first_order_at, MAX(o.placed_at) AS last_order_at
                         FROM ordering_order o WHERE %1$s GROUP BY o.customer_id
                     ) AS a
                     JOIN (
                         SELECT DISTINCT ON (o.customer_id) o.customer_id, o.email, o.full_name, o.phone, o.id
                         FROM ordering_order o WHERE %1$s ORDER BY o.customer_id, o.placed_at DESC, o.id DESC
                     ) AS n ON n.customer_id = a.customer_id
                     WHERE c.customer_id = a.customer_id AND c.customer_id IN (:locked)
                     RETURNING c.order_count',
                    $orders,
                ),
                $parameters,
                $types,
            );
            $connection->executeStatement(
                \sprintf('DELETE FROM ordering_customer c WHERE %s AND c.customer_id IN (:locked) AND NOT EXISTS (SELECT 1 FROM ordering_order o WHERE %s AND o.customer_id = c.customer_id)', $rows, self::CUSTOMER_ORDERS),
                $parameters,
                $types,
            );

            $orders = 0;
            foreach ($counts as $count) {
                \assert(\is_int($count));
                $orders += $count;
            }

            return new CustomerRebuildTotals(\count($counts), $orders);
        });
    }
}

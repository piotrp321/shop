<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\CustomerSummaries;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\OrderId;
use Doctrine\DBAL\Connection;
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
 */
final readonly class DbalCustomerSummaries implements CustomerSummaries
{
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
}

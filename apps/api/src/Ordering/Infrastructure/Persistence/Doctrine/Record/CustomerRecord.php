<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer of the panel: an account of the store (the `sub` of its token) with at least one order, with the contact
 * of its newest order (by placed_at, then by id: last_order_at and last_order_id), the number of its orders and of the
 * paid ones, the gross total of the paid ones and the moments of its first and last order. Only defines the schema:
 * DbalCustomerSummaries writes the rows and DbalCustomerReader reads them, by DBAL. The migration adds the CHECK
 * constraints of the non-negative numbers and of the order of the moments.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_customer')]
// The sorts of the list: the migration makes the first column descending, which the mapping cannot express, and adds
// the trigram indexes of lower(email) and lower(full_name) for the search, which the mapping does not know
// (expressions only, so the schema tools ignore them). The index of the sort by name is (lower(full_name),
// customer_id): the schema tools read back only its plain column, so it is mapped as that, or the diff would drop it.
#[ORM\Index(name: 'ordering_customer_last_order_at_idx', columns: ['last_order_at', 'customer_id'])]
#[ORM\Index(name: 'ordering_customer_name_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'ordering_customer_order_count_idx', columns: ['order_count', 'customer_id'])]
#[ORM\Index(name: 'ordering_customer_paid_gross_idx', columns: ['paid_gross', 'customer_id'])]
class CustomerRecord
{
    #[ORM\Column(length: 254)]
    public string $email;

    #[ORM\Column(length: 100)]
    public string $fullName;

    #[ORM\Column(length: 12)]
    public string $phone;

    #[ORM\Column(type: Types::INTEGER)]
    public int $orderCount = 0;

    #[ORM\Column(type: Types::INTEGER)]
    public int $paidCount = 0;

    /** In grosze, without the refunds taken off. */
    #[ORM\Column(type: Types::BIGINT)]
    public int $paidGross = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $firstOrderAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $lastOrderAt;

    #[ORM\Column(type: 'guid')]
    public string $lastOrderId;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 255)]
        public readonly string $customerId,
    ) {
    }
}

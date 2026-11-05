<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\OrderNumbers;
use App\Ordering\Domain\OrderNumber;
use Doctrine\DBAL\Connection;

/**
 * The next value of the PostgreSQL sequence ordering_order_number_seq (START 100001), through DBAL. A sequence is not
 * part of the transaction: a placement that rolls back leaves a gap.
 */
final readonly class DbalOrderNumbers implements OrderNumbers
{
    public const string SEQUENCE = 'ordering_order_number_seq';

    public function __construct(private Connection $connection)
    {
    }

    public function next(): OrderNumber
    {
        $value = $this->connection->fetchOne(\sprintf("SELECT nextval('%s')", self::SEQUENCE));
        \assert(\is_int($value));

        return OrderNumber::fromSequence($value);
    }
}

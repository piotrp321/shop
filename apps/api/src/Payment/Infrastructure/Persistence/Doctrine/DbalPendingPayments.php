<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Persistence\Doctrine;

use App\Payment\Application\Port\PendingPayment;
use App\Payment\Application\Port\PendingPayments;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads the pending payments through the partial unique index payment_payment_one_pending_per_order (order_id WHERE
 * status = 'pending'): the condition is the one of the index, so only the pending rows are read, in the order of the
 * keyset, whatever the number of payments that ended.
 */
final readonly class DbalPendingPayments implements PendingPayments
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(?OrderId $after, int $limit): array
    {
        $keyset = null === $after ? '' : 'AND order_id > CAST(:after AS uuid)';
        $rows = $this->connection->fetchAllNumeric(
            <<<SQL
                SELECT order_id, id
                FROM payment_payment
                WHERE status = 'pending' {$keyset}
                ORDER BY order_id
                LIMIT :limit
                SQL,
            ['limit' => $limit, ...(null === $after ? [] : ['after' => $after->toString()])],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static function (array $row): PendingPayment {
            \assert(\is_string($row[0]) && \is_string($row[1]));

            return new PendingPayment(OrderId::fromString($row[0]), PaymentId::fromString($row[1]));
        }, $rows);
    }
}

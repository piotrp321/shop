<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Demo;

use App\Payment\Application\Port\PaymentReset;
use Doctrine\DBAL\Connection;

/**
 * Truncates the payments and the handled webhook events in one statement (both or neither).
 */
final readonly class DbalPaymentReset implements PaymentReset
{
    public function __construct(private Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE payment_payment, payment_webhook_event');
    }
}

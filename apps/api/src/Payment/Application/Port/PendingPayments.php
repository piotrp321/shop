<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Domain\OrderId;

/**
 * The pending payments, read without a lock (each one is locked by its own closing).
 */
interface PendingPayments
{
    /**
     * At most $limit pending payments by the id of their order, after the order $after (exclusive) when given.
     *
     * @return list<PendingPayment>
     */
    public function page(?OrderId $after, int $limit): array;
}

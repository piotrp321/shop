<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Application;

use App\Backoffice\Application\Port\OrderQueueCounts;
use App\Backoffice\Application\Port\OrderQueues;

final class RecordingOrderQueues implements OrderQueues
{
    /** @var list<array{bool, bool, bool}> what every call asked for: paid to ship, awaiting payment, refunds */
    public array $asked = [];

    public function __construct(private readonly OrderQueueCounts $counts)
    {
    }

    public function counts(bool $paidToShip, bool $awaitingPayment, bool $refunds): OrderQueueCounts
    {
        $this->asked[] = [$paidToShip, $awaitingPayment, $refunds];

        // The owner answers only what was asked for.
        return new OrderQueueCounts(
            $paidToShip ? $this->counts->paidToShip : null,
            $awaitingPayment ? $this->counts->awaitingPayment : null,
            $refunds ? $this->counts->pendingRefunds : null,
            $refunds ? $this->counts->overdueRefunds : null,
        );
    }
}

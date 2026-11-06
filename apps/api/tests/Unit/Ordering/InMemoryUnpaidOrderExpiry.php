<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\UnpaidOrderExpiry;
use App\Ordering\Domain\OrderId;

/**
 * The ExpireUnpaidOrder messages scheduled, in the order they were scheduled.
 */
final class InMemoryUnpaidOrderExpiry implements UnpaidOrderExpiry
{
    /** @var list<array{string, \DateTimeImmutable}> order id and the moment the message is due */
    public array $scheduled = [];

    public function __construct(private readonly Journal $journal = new Journal())
    {
    }

    public function schedule(OrderId $orderId, \DateTimeImmutable $at): void
    {
        $this->journal->record('schedule');
        $this->scheduled[] = [$orderId->toString(), $at];
    }
}

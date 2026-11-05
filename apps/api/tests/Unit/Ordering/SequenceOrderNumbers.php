<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OrderNumbers;
use App\Ordering\Domain\OrderNumber;

/**
 * The number sequence from 100001; a number taken by a placement that fails is lost, as with the database sequence.
 */
final class SequenceOrderNumbers implements OrderNumbers
{
    private int $next = OrderNumber::FIRST;

    public function __construct(private readonly Journal $journal)
    {
    }

    public function next(): OrderNumber
    {
        $this->journal->record('number');

        return OrderNumber::fromSequence($this->next++);
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * A period of the sales, from the start of one hour (inclusive) to the start of another (exclusive), in UTC.
 */
final readonly class SalesPeriod
{
    public function __construct(public \DateTimeImmutable $from, public \DateTimeImmutable $to)
    {
    }
}

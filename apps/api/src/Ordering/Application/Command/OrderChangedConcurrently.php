<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * The order changed between the read and the save of MarkOrderPaid (e.g. the confirmation email stored its marker,
 * or the order was cancelled). Nothing was changed; a retry reads the order again.
 */
final class OrderChangedConcurrently extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The order changed concurrently.', previous: $previous);
    }
}

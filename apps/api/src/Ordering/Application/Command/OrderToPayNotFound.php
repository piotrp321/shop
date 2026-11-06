<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * MarkOrderPaid for an order that does not exist (or an id that is no UUID): Payment only pays orders it found, so
 * it is a logic error (or the demo data was reset under a payment).
 */
final class OrderToPayNotFound extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The order to pay does not exist.', previous: $previous);
    }
}

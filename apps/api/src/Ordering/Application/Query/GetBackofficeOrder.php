<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The order for the panel: everything a staff member sees of it, with the version the next change refers to.
 */
final readonly class GetBackofficeOrder
{
    /**
     * @param string $orderId a UUID; anything else is an unknown order
     */
    public function __construct(public string $orderId)
    {
    }
}

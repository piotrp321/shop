<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The cart an order is placed from has no line.
 */
final class CartEmpty extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Koszyk jest pusty.');
    }
}

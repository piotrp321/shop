<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The quantity of a line would exceed Cart::MAX_QUANTITY.
 */
final class CartLineQuantityLimit extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Można dodać najwyżej 99 sztuk jednego produktu.');
    }
}

<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * A new line would exceed Cart::MAX_LINES.
 */
final class CartLineLimit extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Koszyk może zawierać najwyżej 50 różnych produktów.');
    }
}

<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * Inventory could not reserve every line of the order. Carries no number of units.
 */
final class InsufficientStockForOrder extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.');
    }
}

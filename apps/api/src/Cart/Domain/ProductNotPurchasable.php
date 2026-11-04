<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The product has no current price or no available unit, so it cannot be added or increased.
 */
final class ProductNotPurchasable extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Ten produkt jest chwilowo niedostępny w sprzedaży.');
    }
}

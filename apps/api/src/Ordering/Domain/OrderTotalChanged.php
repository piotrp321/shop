<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The gross total computed now differs from the one the customer saw (decision P2), higher or lower.
 */
final class OrderTotalChanged extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Kwota zamówienia się zmieniła. Sprawdź podsumowanie.');
    }
}

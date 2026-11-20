<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The method is not at the version the change is based on.
 */
final class DeliveryMethodModifiedConcurrently extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Metoda dostawy została w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.');
    }
}

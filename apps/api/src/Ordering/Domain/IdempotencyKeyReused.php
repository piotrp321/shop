<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The Idempotency-Key was used by an order of another request (another cart, customer or content). Carries no data of that order.
 */
final class IdempotencyKeyReused extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Ten klucz żądania był już użyty dla innego zamówienia.');
    }
}

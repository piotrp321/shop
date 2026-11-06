<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The order changed since it was read: the version it was read at is no longer stored.
 */
final class OrderModifiedConcurrently extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Zamówienie zostało w międzyczasie zmienione.');
    }
}

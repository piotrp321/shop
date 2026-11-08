<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

use App\Shared\Domain\Exception\Conflict;

/**
 * The code is not at the version the change is based on.
 */
final class DiscountCodeModifiedConcurrently extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Kod rabatowy został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.');
    }
}

<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * The quantity would exceed the available units. The message never tells how many units there are.
 */
final class InsufficientStock extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Nie mamy tylu sztuk tego produktu.');
    }
}

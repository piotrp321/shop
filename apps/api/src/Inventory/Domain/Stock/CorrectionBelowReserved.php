<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\Conflict;

/**
 * A correction would leave less on the shelf than active reservations hold. The message, shown to the staff member
 * as it is, names the reserved quantity: a number of the stock, not of the request.
 */
final class CorrectionBelowReserved extends \DomainException implements Conflict
{
    public function __construct(public readonly int $reserved)
    {
        parent::__construct(\sprintf('Stan fizyczny nie może być niższy niż zarezerwowany (%d szt.).', $reserved));
    }
}

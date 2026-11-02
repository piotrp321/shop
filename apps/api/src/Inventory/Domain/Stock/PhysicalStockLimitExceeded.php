<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A receipt would take the physical stock above 1 000 000 units. The message is shown to the staff member as it is.
 */
final class PhysicalStockLimitExceeded extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('Stan fizyczny nie może przekroczyć 1 000 000 szt.');
    }
}

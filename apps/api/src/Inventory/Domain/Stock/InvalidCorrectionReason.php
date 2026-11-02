<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The message never contains the submitted value.
 */
final class InvalidCorrectionReason extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct(\sprintf(
            'The reason of a correction must be %s.',
            preg_replace('/, (?!.*, )/', ' or ', implode(', ', array_map(static fn (CorrectionReason $reason): string => $reason->value, CorrectionReason::cases()))),
        ));
    }
}

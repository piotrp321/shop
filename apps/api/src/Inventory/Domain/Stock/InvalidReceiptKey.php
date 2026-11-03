<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The message never contains the submitted value.
 */
final class InvalidReceiptKey extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct(\sprintf('A receipt key must have 1 to %d characters: letters, digits, ".", "_", ":" or "-".', ReceiptKey::MAX_LENGTH));
    }
}

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
        // The text of the malformed key of an order and of a payment.
        parent::__construct('Nieprawidłowy klucz żądania.');
    }
}

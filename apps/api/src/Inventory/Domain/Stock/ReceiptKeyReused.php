<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\Conflict;

/**
 * The receipt key belongs to a receipt of another quantity, with another note or into another product. The message
 * is shown to the staff member as it is.
 */
final class ReceiptKeyReused extends \DomainException implements Conflict
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Klucz przyjęcia został już użyty z inną treścią albo dla innego produktu.', 0, $previous);
    }
}

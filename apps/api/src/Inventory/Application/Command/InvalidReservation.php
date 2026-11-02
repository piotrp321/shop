<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Reservation\InvalidReservation as InvalidReservationInDomain;
use App\Shared\Domain\Exception\InvalidInput;

/**
 * A reservation request with a malformed key, a line that is not valid (product id, quantity) or an invalid set of
 * lines (count, a repeated product). The exception of the reservation port: a caller outside Inventory depends only
 * on its Application layer. The message never contains the submitted value.
 */
final class InvalidReservation extends \DomainException implements InvalidInput
{
    public static function fromDomain(InvalidReservationInDomain $invalid): self
    {
        return new self($invalid->getMessage(), 0, $invalid);
    }

    public static function notAProductId(): self
    {
        return new self('A product id must be a UUID.');
    }
}

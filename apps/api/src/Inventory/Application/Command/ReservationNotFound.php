<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Shared\Domain\Exception\NotFound;

final class ReservationNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('The reservation does not exist.');
    }
}

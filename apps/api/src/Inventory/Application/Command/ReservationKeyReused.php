<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Shared\Domain\Exception\Conflict;

final class ReservationKeyReused extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('The reservation key was already used with other lines.');
    }
}

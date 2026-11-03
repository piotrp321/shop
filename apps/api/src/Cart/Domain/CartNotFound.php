<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\NotFound;

/**
 * No such guest cart, or it expired: an expired guest cart does not exist for commands and reads.
 */
final class CartNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Koszyk nie jest już dostępny.');
    }
}

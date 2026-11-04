<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\NotFound;

/**
 * The guest cart was merged into a customer cart and never changes again. Answers like an unknown cart.
 */
final class GuestCartMerged extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Koszyk nie jest już dostępny.');
    }
}

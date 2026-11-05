<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Shared\Domain\Exception\NotFound;

/**
 * What the cart port throws for a guest cart that is unknown, expired or merged (the adapter passes on CartNotFound
 * of Cart; Ordering_Application knows only that it is a NotFound).
 */
final class CartGone extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Koszyk nie jest już dostępny.');
    }
}

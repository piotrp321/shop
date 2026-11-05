<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\NotFound;

/**
 * No order with this id.
 */
final class OrderNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Zamówienie nie istnieje.');
    }
}

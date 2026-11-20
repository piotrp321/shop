<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Shared\Domain\Exception\NotFound;

final class DeliveryMethodNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Metoda dostawy nie istnieje.');
    }
}

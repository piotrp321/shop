<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Exception\NotFound;

/**
 * No customer with this id: an account without orders, a guest or an id nobody has.
 */
final class CustomerNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Klient nie istnieje.');
    }
}

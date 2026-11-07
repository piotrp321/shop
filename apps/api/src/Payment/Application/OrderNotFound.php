<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Shared\Domain\Exception\NotFound;

/**
 * No order the caller has access to: unknown, an id that is no UUID, a wrong or missing token, somebody else's order
 * or an order of the other kind of owner. One text for all of them.
 */
final class OrderNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Nie znaleźliśmy zamówienia.');
    }
}

<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Shared\Domain\Exception\NotFound;

/**
 * A webhook about a session the shop has no payment of (at that provider). A 404, so the provider stops retrying.
 */
final class PaymentNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Nie znaleźliśmy płatności.');
    }
}

<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

use App\Shared\Domain\Exception\NotFound;

final class DiscountCodeNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Kod rabatowy nie istnieje.');
    }
}

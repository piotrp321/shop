<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Shared\Domain\Exception\NotFound;

final class VariantNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Wariant produktu nie istnieje.');
    }
}

<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Shared\Domain\Exception\Conflict;

final class PriceModifiedConcurrently extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('The price was modified concurrently. Reload it and try again.');
    }
}

<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Tax;

use App\Shared\Domain\Exception\InvalidInput;

final class TaxClassWithoutRate extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('The tax class of the product has no VAT rate.');
    }
}

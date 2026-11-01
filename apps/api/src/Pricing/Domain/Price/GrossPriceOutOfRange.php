<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Shared\Domain\Exception\InvalidInput;

final class GrossPriceOutOfRange extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('The gross price must be between 0.01 and 999 999.99 PLN.');
    }
}

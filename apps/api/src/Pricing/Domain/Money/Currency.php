<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Money;

/**
 * ISO 4217 code. The shop sells only in Poland, so PLN is the only currency.
 */
enum Currency: string
{
    case PLN = 'PLN';
}

<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The currencies a payment is made in: only the złoty.
 */
enum Currency: string
{
    case PLN = 'PLN';
}

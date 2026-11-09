<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The kind of the discount code an order was placed with: a percent off or an amount off.
 */
enum OrderDiscountKind: string
{
    case Percent = 'percent';
    case Amount = 'amount';
}

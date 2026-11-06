<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The currencies an order is placed in: only the złoty.
 */
enum Currency: string
{
    case PLN = 'PLN';
}

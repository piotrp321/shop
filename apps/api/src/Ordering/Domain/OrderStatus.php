<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The first status of the order cycle (new → paid → shipped → delivered); PAYMENT and ORDER-MANAGEMENT add the
 * transitions.
 */
enum OrderStatus: string
{
    case New = 'new';
}

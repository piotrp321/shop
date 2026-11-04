<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * Only a guest cart is ever merged (into the cart of the customer who logged in); a merged cart never changes again.
 */
enum CartStatus: string
{
    case Open = 'open';
    case Merged = 'merged';
}

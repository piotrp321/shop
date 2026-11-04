<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * Whether a quantity of a product can be bought, as ProductOffer::purchasability() decides. The cases follow the order
 * of the rule: the first one that holds is the answer.
 */
enum Purchasability
{
    /** The product has no current price. */
    case NoPrice;
    /** No unit is available. */
    case OutOfStock;
    /** The quantity is above the available units. */
    case InsufficientStock;
    case Purchasable;
}

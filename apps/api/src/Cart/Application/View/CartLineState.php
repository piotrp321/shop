<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The state of a cart line, in the order of priority: the first that applies wins.
 */
enum CartLineState: string
{
    /** Catalog does not know the product (e.g. after a reset of the demo data). */
    case UnavailableProduct = 'unavailable_product';
    /** Pricing has no current price (none, or at an outdated VAT rate). */
    case NoPrice = 'no_price';
    /** No unit is available. */
    case OutOfStock = 'out_of_stock';
    /** The quantity is above the available units. */
    case InsufficientStock = 'insufficient_stock';
    case Purchasable = 'purchasable';
}

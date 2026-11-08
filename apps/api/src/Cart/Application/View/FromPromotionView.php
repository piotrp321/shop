<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The promotion of the variant that gives the "from" price of a product: its Omnibus price (in grosze; null when not
 * asked for or the variant had no price before the start).
 */
final readonly class FromPromotionView
{
    public function __construct(public ?int $lowestPriorGross)
    {
    }
}

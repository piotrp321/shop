<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\PromotionCopy;

/**
 * A line of the quote: the effective unit price, the values of the line (its share of the discount, net, VAT and
 * gross after it) and the promotion that gave the price.
 */
final readonly class QuotedOrderLine
{
    public function __construct(
        public PriceCopy $unitPrice,
        public LineAmounts $amounts,
        public ?PromotionCopy $promotion,
    ) {
    }
}

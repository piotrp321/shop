<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

/**
 * The end of an active promotion moved to another future moment: the products of the promotion show its end (the
 * offer and `priceValidUntil` of the store), so they are refreshed at once. Sent without a delay (transport
 * `promotions`) by the revise of the promotion.
 */
final readonly class RefreshPromotionProducts
{
    public function __construct(public string $promotionId)
    {
    }
}

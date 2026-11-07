<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * A promotion with its variants as Catalog shows them and their catalog and promotional prices, for the panel.
 */
final readonly class GetPromotion
{
    public function __construct(public string $id)
    {
    }
}

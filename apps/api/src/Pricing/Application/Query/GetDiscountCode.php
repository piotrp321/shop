<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * A discount code with its active usages, for the panel.
 */
final readonly class GetDiscountCode
{
    public function __construct(public string $id)
    {
    }
}

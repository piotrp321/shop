<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

final readonly class CartSummary
{
    public function __construct(public int $itemCount)
    {
    }
}
